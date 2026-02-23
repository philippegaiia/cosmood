<?php

namespace App\Observers;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Services\Production\ProductionItemGenerationService;
use App\Services\Production\ProductionQcGenerationService;
use App\Services\Production\TaskGenerationService;

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

    public function __construct(
        private readonly TaskGenerationService $taskGenerationService,
        private readonly ProductionItemGenerationService $productionItemGenerationService,
        private readonly ProductionQcGenerationService $productionQcGenerationService,
    ) {}

    public function created(Production $production): void
    {
        $this->productionItemGenerationService->generateFromFormula($production);
        $this->productionQcGenerationService->generateChecksForProduction($production);

        if ($production->status === ProductionStatus::Confirmed) {
            $this->taskGenerationService->generateTasksForProduction($production);
        }
    }

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

        if (! $production->wasChanged('status')) {
            return;
        }

        if ($production->status === ProductionStatus::Confirmed) {
            $this->taskGenerationService->generateTasksForProduction($production);

            return;
        }

        if (in_array($production->status, self::TASKLESS_STATUSES, true)) {
            $production->productionTasks()->delete();
        }
    }
}
