<?php

namespace App\Models\Production;

use App\Enums\ProductionStatus;
use App\Enums\WaveStatus;
use App\Models\User;
use App\Services\Production\WaveProcurementService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class ProductionWave extends Model
{
    use HasFactory;

    private static bool $allowsManagedDeletion = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(function (ProductionWave $wave): void {
            if (self::$allowsManagedDeletion) {
                return;
            }

            throw new InvalidArgumentException(__('Utilisez la suppression définitive de la vague pour supprimer également ses productions liées.'));
        });
    }

    protected function casts(): array
    {
        return [
            'status' => WaveStatus::class,
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function productions(): HasMany
    {
        return $this->hasMany(Production::class, 'production_wave_id');
    }

    public function stockDecisions(): HasMany
    {
        return $this->hasMany(ProductionWaveStockDecision::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === WaveStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === WaveStatus::Approved;
    }

    public function isInProgress(): bool
    {
        return $this->status === WaveStatus::InProgress;
    }

    public function isCompleted(): bool
    {
        return $this->status === WaveStatus::Completed;
    }

    public function isCancelled(): bool
    {
        return $this->status === WaveStatus::Cancelled;
    }

    public function approve(User $user, ?CarbonInterface $plannedStartDate = null, ?CarbonInterface $plannedEndDate = null): void
    {
        if (! $this->isDraft()) {
            throw new InvalidArgumentException('Only draft waves can be approved');
        }

        $this->update([
            'status' => WaveStatus::Approved,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'planned_start_date' => $plannedStartDate ?? $this->planned_start_date,
            'planned_end_date' => $plannedEndDate ?? $this->planned_end_date,
        ]);
    }

    public function start(): void
    {
        if (! $this->isApproved()) {
            throw new InvalidArgumentException('Only approved waves can be started');
        }

        $this->update([
            'status' => WaveStatus::InProgress,
            'started_at' => now(),
        ]);
    }

    public function complete(): void
    {
        if (! $this->isInProgress()) {
            throw new InvalidArgumentException('Only in progress waves can be completed');
        }

        $openProductionBatches = $this->getOpenProductionBatches();

        if ($openProductionBatches !== []) {
            throw new InvalidArgumentException(__('Impossible de terminer la vague: productions encore actives (:batches).', [
                'batches' => implode(', ', $openProductionBatches),
            ]));
        }

        $this->update([
            'status' => WaveStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function cancel(): void
    {
        if ($this->isCompleted()) {
            throw new InvalidArgumentException('Completed waves cannot be cancelled');
        }

        $this->update([
            'status' => WaveStatus::Cancelled,
        ]);
    }

    public function hasStartedProductions(): bool
    {
        return $this->productions()
            ->whereIn('status', [
                ProductionStatus::Ongoing->value,
                ProductionStatus::Finished->value,
            ])
            ->exists();
    }

    public function hasNonTerminalProductions(): bool
    {
        return $this->productions()
            ->whereNotIn('status', [
                ProductionStatus::Finished->value,
                ProductionStatus::Cancelled->value,
            ])
            ->exists();
    }

    public function getStatusAdvisoryMessage(): ?string
    {
        if ($this->isApproved() && $this->hasStartedProductions()) {
            return __('Des productions liées ont démarré. Passez la vague en cours pour garder le suivi cohérent.');
        }

        if ($this->isInProgress() && ! $this->hasNonTerminalProductions() && $this->productions()->exists()) {
            return __('Toutes les productions liées sont terminées ou annulées. Vous pouvez clôturer la vague.');
        }

        return null;
    }

    public function getCoverageSignalLabel(): string
    {
        return $this->getCoverageSignal()['label'];
    }

    public function getCoverageSignalColor(): string
    {
        return $this->getCoverageSignal()['color'];
    }

    public function getCoverageSignalTooltip(): string
    {
        if ((int) ($this->productions_count ?? 0) === 0 && ! $this->productions()->exists()) {
            return __('Aucune production liée.');
        }

        $service = app(WaveProcurementService::class);
        $lines = $service->getPlanningList($this);

        return __('Besoin total: :total | Besoin restant: :remaining | Reste à sécuriser: :toSecure | Reste à commander: :toOrder', [
            'total' => $service->formatPlanningQuantityByUnit($lines, 'total_wave_requirement'),
            'remaining' => $service->formatPlanningQuantityByUnit($lines, 'remaining_requirement'),
            'toSecure' => $service->formatPlanningQuantityByUnit($lines, 'remaining_to_secure'),
            'toOrder' => $service->formatPlanningQuantityByUnit($lines, 'remaining_to_order'),
        ]);
    }

    /**
     * @return array{label: string, color: string}
     */
    private function getCoverageSignal(): array
    {
        if ((int) ($this->productions_count ?? 0) === 0 && ! $this->productions()->exists()) {
            return [
                'label' => __('Sans besoin'),
                'color' => 'gray',
            ];
        }

        $lines = app(WaveProcurementService::class)->getPlanningList($this);

        if ($lines->isEmpty()) {
            return [
                'label' => __('Sans besoin'),
                'color' => 'gray',
            ];
        }

        $hasRemainingRequirement = $lines->contains(fn (object $line): bool => (float) ($line->remaining_requirement ?? 0) > 0);
        $hasRemainingToOrder = $lines->contains(fn (object $line): bool => (float) ($line->remaining_to_order ?? 0) > 0);
        $hasPartialCoverage = $lines->contains(fn (object $line): bool => (float) ($line->remaining_to_secure ?? 0) > 0)
            || $lines->contains(fn (object $line): bool => (float) ($line->available_stock ?? 0) > 0 && (float) ($line->remaining_requirement ?? 0) > 0);

        if (! $hasRemainingRequirement) {
            return [
                'label' => __('Prête'),
                'color' => 'success',
            ];
        }

        if ($hasRemainingToOrder) {
            return [
                'label' => __('À sécuriser'),
                'color' => 'danger',
            ];
        }

        if ($hasPartialCoverage) {
            return [
                'label' => __('Partielle'),
                'color' => 'warning',
            ];
        }

        return [
            'label' => __('Prête'),
            'color' => 'success',
        ];
    }

    /**
     * @return array<string, float>
     */
    private function getProcurementSummary(): array
    {
        if (! $this->exists) {
            return [];
        }

        /** @var array<string, float> $summary */
        return app(WaveProcurementService::class)->getPlanningSummary($this);
    }

    /**
     * @return array<int, string>
     */
    private function getOpenProductionBatches(int $limit = 5): array
    {
        return $this->productions()
            ->whereNotIn('status', [
                ProductionStatus::Finished->value,
                ProductionStatus::Cancelled->value,
            ])
            ->orderBy('production_date')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('batch_number')
            ->map(fn (?string $batchNumber): string => (string) ($batchNumber ?: __('Sans référence')))
            ->all();
    }

    /**
     * Temporarily allows direct wave deletion inside the guarded service only.
     *
     * Waves must not be deleted ad hoc because their productions need to be
     * deleted in the same transaction and with the same blocker checks.
     */
    public static function allowManagedDeletion(callable $callback): mixed
    {
        self::$allowsManagedDeletion = true;

        try {
            return $callback();
        } finally {
            self::$allowsManagedDeletion = false;
        }
    }
}
