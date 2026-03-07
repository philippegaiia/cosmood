<?php

namespace App\Observers;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Services\Production\ManufacturedIngredientStockService;
use App\Services\Production\PermanentBatchNumberService;
use App\Services\Production\ProductionAllocationService;
use App\Services\Production\ProductionItemGenerationService;
use App\Services\Production\ProductionQcGenerationService;
use App\Services\Production\TaskGenerationService;
use App\Services\Production\WaveRequirementStatusService;

class ProductionObserver
{
    private const TASK_DELETION_STATUSES = [
        ProductionStatus::Cancelled,
    ];

    private const RESCHEDULABLE_STATUSES = [
        ProductionStatus::Planned,
        ProductionStatus::Confirmed,
        ProductionStatus::Ongoing,
    ];

    private const PERMANENT_BATCH_ASSIGNABLE_STATUSES = [
        ProductionStatus::Ongoing,
        ProductionStatus::Finished,
    ];

    public function __construct(
        private readonly TaskGenerationService $taskGenerationService,
        private readonly ProductionItemGenerationService $productionItemGenerationService,
        private readonly ProductionQcGenerationService $productionQcGenerationService,
        private readonly PermanentBatchNumberService $permanentBatchNumberService,
        private readonly ManufacturedIngredientStockService $manufacturedIngredientStockService,
        private readonly ProductionAllocationService $allocationService,
        private readonly WaveRequirementStatusService $waveRequirementStatusService,
    ) {}

    public function created(Production $production): void
    {
        $this->productionItemGenerationService->generateFromFormula($production);
        $this->productionQcGenerationService->generateChecksForProduction($production);

        if ($production->status !== ProductionStatus::Cancelled) {
            $this->taskGenerationService->generateTasksForProduction($production);
        }

        if (in_array($production->status, self::PERMANENT_BATCH_ASSIGNABLE_STATUSES, true)) {
            $this->permanentBatchNumberService->assignIfMissing($production);
        }

        if ($production->status === ProductionStatus::Finished) {
            $this->manufacturedIngredientStockService->ensureStockFromFinishedProduction($production);
        }

        $this->handleAllocationLifecycle($production);
        $this->syncWaveRequirementStatuses([$production->production_wave_id]);
    }

    public function updated(Production $production): void
    {
        if ($production->wasChanged('product_type_id') && ! $production->productionQcChecks()->exists()) {
            $this->productionQcGenerationService->generateChecksForProduction($production);
        }

        if (in_array($production->status, self::TASK_DELETION_STATUSES, true)) {
            $production->productionTasks()->delete();
        }

        if ($production->wasChanged('production_date') && in_array($production->status, self::RESCHEDULABLE_STATUSES, true)) {
            $this->taskGenerationService->rescheduleTasks($production);
        }

        $statusChanged = $production->wasChanged('status');

        if ($statusChanged && $production->status !== ProductionStatus::Cancelled) {
            $this->taskGenerationService->generateTasksForProduction($production);
        }

        if ($statusChanged && in_array($production->status, self::PERMANENT_BATCH_ASSIGNABLE_STATUSES, true)) {
            $this->permanentBatchNumberService->assignIfMissing($production);
        }

        if ($production->status === ProductionStatus::Finished && ($statusChanged || $production->wasChanged('produced_ingredient_id'))) {
            $this->manufacturedIngredientStockService->ensureStockFromFinishedProduction($production);
        }

        if ($statusChanged) {
            $this->handleAllocationLifecycle($production);
        }

        $waveIdsToSync = [$production->production_wave_id];

        if ($production->wasChanged('production_wave_id')) {
            $waveIdsToSync[] = $production->getRawOriginal('production_wave_id');
        }

        if ($production->wasChanged('status') || $production->wasChanged('production_wave_id') || $production->wasChanged('production_date') || $production->wasChanged('masterbatch_lot_id')) {
            $this->syncWaveRequirementStatuses($waveIdsToSync);
        }
    }

    public function deleting(Production $production): void
    {
        $this->allocationService->releaseForProduction($production);
    }

    public function deleted(Production $production): void
    {
        $this->syncWaveRequirementStatuses([$production->production_wave_id]);
    }

    private function handleAllocationLifecycle(Production $production): void
    {
        match ($production->status) {
            ProductionStatus::Ongoing => $this->allocationService->consumeForProduction(
                $production,
                includePackaging: false,
                includeNonPackaging: true,
            ),
            ProductionStatus::Finished => $this->allocationService->consumeForProduction(
                $production,
                includePackaging: true,
                includeNonPackaging: true,
            ),
            ProductionStatus::Cancelled => $this->allocationService->releaseForProduction($production),
            default => null,
        };
    }

    /**
     * @param  array<int, int|string|null>  $waveIds
     */
    private function syncWaveRequirementStatuses(array $waveIds): void
    {
        collect($waveIds)
            ->filter(fn (mixed $waveId): bool => (int) $waveId > 0)
            ->map(fn (mixed $waveId): int => (int) $waveId)
            ->unique()
            ->each(function (int $waveId): void {
                $wave = \App\Models\Production\ProductionWave::query()->find($waveId);

                if (! $wave) {
                    return;
                }

                $this->waveRequirementStatusService->syncForWave($wave);
            });
    }
}
