<?php

namespace App\Observers;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Services\Production\ManufacturedIngredientStockService;
use App\Services\Production\PermanentBatchNumberService;
use App\Services\Production\ProductionItemGenerationService;
use App\Services\Production\ProductionQcGenerationService;
use App\Services\Production\ProductionStockLifecycleService;
use App\Services\Production\TaskGenerationService;

/**
 * Orchestrates production side effects across status and scheduling lifecycle changes.
 */
class ProductionObserver
{
    /**
     * @var array<int, ProductionStatus>
     */
    private const TASK_DELETION_STATUSES = [
        ProductionStatus::Cancelled,
    ];

    /**
     * @var array<int, ProductionStatus>
     */
    private const RESCHEDULABLE_STATUSES = [
        ProductionStatus::Planned,
        ProductionStatus::Confirmed,
        ProductionStatus::Ongoing,
    ];

    /**
     * @var array<int, ProductionStatus>
     */
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
        private readonly ProductionStockLifecycleService $productionStockLifecycleService,
    ) {}

    /**
     * Generates initial derived records and triggers status-dependent automations.
     */
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

        $this->productionStockLifecycleService->syncForStatus($production);
    }

    /**
     * Reacts to status/date/type updates and keeps generated artifacts in sync.
     */
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

        if (
            $statusChanged
            || $production->wasChanged('planned_quantity')
            || $production->wasChanged('expected_units')
            || $production->wasChanged('masterbatch_lot_id')
        ) {
            $this->productionStockLifecycleService->syncForStatus($production);
        }
    }

    /**
     * Releases reservations and staged consumptions before a production is deleted.
     */
    public function deleting(Production $production): void
    {
        $this->productionStockLifecycleService->rollbackForDeletion($production);
    }
}
