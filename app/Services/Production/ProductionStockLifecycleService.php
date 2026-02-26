<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;

/**
 * Applies stock side effects according to the production lifecycle status.
 *
 * Invariants:
 * - Planned / Confirmed keep all selected lots reserved.
 * - Ongoing consumes non-packaging inputs and keeps packaging reserved.
 * - Finished consumes packaging and keeps no reservation.
 * - Cancelled keeps no reservation.
 */
class ProductionStockLifecycleService
{
    public function __construct(
        private readonly ProductionReservationService $productionReservationService,
        private readonly ProductionConsumptionService $productionConsumptionService,
    ) {}

    /**
     * Synchronizes reservations and consumptions for the production current status.
     */
    public function syncForStatus(Production $production): void
    {
        if ($production->status === ProductionStatus::Planned || $production->status === ProductionStatus::Confirmed) {
            $this->productionReservationService->syncForPlanning($production);

            return;
        }

        if ($production->status === ProductionStatus::Ongoing) {
            $this->productionReservationService->syncForOngoing($production);
            $this->productionConsumptionService->consumeForOngoingProduction($production);

            return;
        }

        if ($production->status === ProductionStatus::Finished) {
            $this->productionReservationService->releaseForProduction($production);
            $this->productionConsumptionService->consumeForOngoingProduction($production);
            $this->productionConsumptionService->consumeForFinishedProduction($production);

            return;
        }

        if ($production->status === ProductionStatus::Cancelled) {
            $this->productionReservationService->releaseForProduction($production);
        }
    }

    /**
     * Reverts all stock effects associated with a production before deletion.
     */
    public function rollbackForDeletion(Production $production): void
    {
        $this->productionReservationService->releaseForProduction($production);
        $this->productionConsumptionService->rollbackAllComputedConsumption($production);
    }
}
