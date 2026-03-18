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
        if (($preloaded = $this->getPreloadedProductionExistsFlag('has_started_productions')) !== null) {
            return $preloaded;
        }

        return $this->productions()
            ->whereIn('status', [
                ProductionStatus::Ongoing->value,
                ProductionStatus::Finished->value,
            ])
            ->exists();
    }

    public function hasNonTerminalProductions(): bool
    {
        if (($preloaded = $this->getPreloadedProductionExistsFlag('has_non_terminal_productions')) !== null) {
            return $preloaded;
        }

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

        if ($this->isInProgress() && ! $this->hasNonTerminalProductions() && $this->hasLinkedProductions()) {
            return __('Toutes les productions liées sont terminées ou annulées. Vous pouvez clôturer la vague.');
        }

        return null;
    }

    public function getCoverageSignalLabel(): string
    {
        return $this->getCoverageSnapshot()['label'];
    }

    public function getCoverageSignalColor(): string
    {
        return $this->getCoverageSnapshot()['color'];
    }

    public function getCoverageSignalTooltip(): string
    {
        return $this->getCoverageSnapshot()['tooltip'];
    }

    public function getFabricationSignalLabel(): string
    {
        return $this->getFabricationSnapshot()['label'];
    }

    public function getFabricationSignalColor(): string
    {
        return $this->getFabricationSnapshot()['color'];
    }

    public function getFabricationSignalTooltip(): string
    {
        return $this->getFabricationSnapshot()['tooltip'];
    }

    /**
     * @return array{label: string, color: string, tooltip: string}
     */
    private function getCoverageSnapshot(): array
    {
        return once(function (): array {
            if (! $this->exists) {
                return $this->getDefaultCoverageSnapshot();
            }

            return app(WaveProcurementService::class)
                ->getCoverageSnapshotForWaves(collect([$this]))
                ->get($this->id, $this->getDefaultCoverageSnapshot());
        });
    }

    /**
     * @return array{label: string, color: string, tooltip: string}
     */
    private function getFabricationSnapshot(): array
    {
        return once(function (): array {
            if (! $this->exists) {
                return $this->getDefaultFabricationSnapshot();
            }

            return app(WaveProcurementService::class)
                ->getFabricationSnapshotForWaves(collect([$this]))
                ->get($this->id, $this->getDefaultFabricationSnapshot());
        });
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

    private function hasLinkedProductions(): bool
    {
        if (array_key_exists('productions_count', $this->getAttributes())) {
            return (int) ($this->getAttribute('productions_count') ?? 0) > 0;
        }

        return $this->productions()->exists();
    }

    private function getPreloadedProductionExistsFlag(string $attribute): ?bool
    {
        if (! array_key_exists($attribute, $this->getAttributes())) {
            return null;
        }

        return (bool) $this->getAttribute($attribute);
    }

    /**
     * @return array{label: string, color: string, tooltip: string}
     */
    private function getDefaultCoverageSnapshot(): array
    {
        return [
            'label' => __('Sans besoin'),
            'color' => 'gray',
            'tooltip' => __('Aucune production liée.'),
        ];
    }

    /**
     * @return array{label: string, color: string, tooltip: string}
     */
    private function getDefaultFabricationSnapshot(): array
    {
        return [
            'label' => __('Sans besoin'),
            'color' => 'gray',
            'tooltip' => __('Aucune production liée.'),
        ];
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
