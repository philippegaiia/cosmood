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
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionWave extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

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
            throw new \InvalidArgumentException('Only draft waves can be approved');
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
            throw new \InvalidArgumentException('Only approved waves can be started');
        }

        $this->update([
            'status' => WaveStatus::InProgress,
            'started_at' => now(),
        ]);
    }

    public function complete(): void
    {
        if (! $this->isInProgress()) {
            throw new \InvalidArgumentException('Only in progress waves can be completed');
        }

        $openProductionBatches = $this->getOpenProductionBatches();

        if ($openProductionBatches !== []) {
            throw new \InvalidArgumentException(__('Impossible de terminer la vague: productions encore actives (:batches).', [
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
            throw new \InvalidArgumentException('Completed waves cannot be cancelled');
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

        $summary = $this->getProcurementSummary();

        return __('Besoin: :need kg | Reste à sécuriser: :toSecure kg | Manque indicatif: :shortage kg', [
            'need' => number_format((float) ($summary['required_remaining_total'] ?? 0), 3, ',', ' '),
            'toSecure' => number_format((float) ($summary['to_secure_total'] ?? 0), 3, ',', ' '),
            'shortage' => number_format((float) ($summary['shortage_total'] ?? 0), 3, ',', ' '),
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

        $summary = $this->getProcurementSummary();
        $requiredRemaining = (float) ($summary['required_remaining_total'] ?? 0);
        $advisoryShortage = (float) ($summary['shortage_total'] ?? 0);
        $toSecure = (float) ($summary['to_secure_total'] ?? 0);
        $provisional = (float) ($summary['provisional_total'] ?? 0);

        if ($requiredRemaining <= 0) {
            return [
                'label' => __('Prête'),
                'color' => 'success',
            ];
        }

        if ($advisoryShortage > 0) {
            return [
                'label' => __('À sécuriser'),
                'color' => 'danger',
            ];
        }

        if ($toSecure > 0 || $provisional > 0) {
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
}
