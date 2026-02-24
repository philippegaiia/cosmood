<?php

namespace App\Observers;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Services\Production\ManufacturedIngredientStockService;
use App\Services\Production\PermanentBatchNumberService;
use App\Services\Production\ProductionConsumptionService;
use App\Services\Production\ProductionItemGenerationService;
use App\Services\Production\ProductionQcGenerationService;
use App\Services\Production\TaskGenerationService;

/**
 * Orchestrates production side effects across status and scheduling lifecycle changes.
 */
class ProductionObserver
{
    /**
     * @var array<int, ProductionStatus>
     */
    private const TASKLESS_STATUSES = [
        ProductionStatus::Simulated,
        ProductionStatus::Planned,
        ProductionStatus::Cancelled,
    ];

    /**
     * @var array<int, ProductionStatus>
     */
    private const RESCHEDULABLE_STATUSES = [
        ProductionStatus::Confirmed,
        ProductionStatus::Ongoing,
        ProductionStatus::Finished,
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
        private readonly ProductionConsumptionService $productionConsumptionService,
    ) {}

    /**
     * Generates initial derived records and triggers status-dependent automations.
     */
    public function created(Production $production): void
    {
        $this->productionItemGenerationService->generateFromFormula($production);
        $this->productionQcGenerationService->generateChecksForProduction($production);

        if ($production->status === ProductionStatus::Confirmed) {
            $this->taskGenerationService->generateTasksForProduction($production);
        }

        if (in_array($production->status, self::PERMANENT_BATCH_ASSIGNABLE_STATUSES, true)) {
            $this->permanentBatchNumberService->assignIfMissing($production);
        }

        if ($production->status === ProductionStatus::Finished) {
            $this->manufacturedIngredientStockService->ensureStockFromFinishedProduction($production);
            $this->productionConsumptionService->consumeForFinishedProduction($production);
        }
    }

    /**
     * Reacts to status/date/type updates and keeps generated artifacts in sync.
     */
    public function updated(Production $production): void
    {
        if ($production->wasChanged('product_type_id') && ! $production->productionQcChecks()->exists()) {
            $this->productionQcGenerationService->generateChecksForProduction($production);
        }

        if (in_array($production->status, self::TASKLESS_STATUSES, true)) {
            $production->productionTasks()->delete();

            return;
        }

        if ($production->wasChanged('production_date') && in_array($production->status, self::RESCHEDULABLE_STATUSES, true)) {
            $this->taskGenerationService->rescheduleTasks($production);
        }

        $statusChanged = $production->wasChanged('status');
        $finishedIngredientChanged = $production->status === ProductionStatus::Finished
            && $production->wasChanged('produced_ingredient_id');

        if (! $statusChanged && ! $finishedIngredientChanged) {
            return;
        }

        if ($production->status === ProductionStatus::Confirmed) {
            $this->taskGenerationService->generateTasksForProduction($production);

            return;
        }

        if (in_array($production->status, self::PERMANENT_BATCH_ASSIGNABLE_STATUSES, true)) {
            $this->permanentBatchNumberService->assignIfMissing($production);
        }

        if (
            $production->status === ProductionStatus::Finished
            && ($statusChanged || $finishedIngredientChanged)
        ) {
            $this->manufacturedIngredientStockService->ensureStockFromFinishedProduction($production);
            $this->productionConsumptionService->consumeForFinishedProduction($production);

            return;
        }

        if (in_array($production->status, self::TASKLESS_STATUSES, true)) {
            $production->productionTasks()->delete();
        }
    }
}
