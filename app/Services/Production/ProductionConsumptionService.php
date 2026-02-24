<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Supply\SuppliesMovement;
use App\Models\Supply\Supply;
use App\Services\InventoryMovementService;
use Illuminate\Support\Facades\DB;

/**
 * Converts finished productions into stock outbound movements and quantity updates.
 */
class ProductionConsumptionService
{
    public function __construct(
        private readonly InventoryMovementService $inventoryMovementService,
        private readonly MasterbatchService $masterbatchService,
    ) {}

    /**
     * Consumes linked supplies for a finished production.
     *
     * For masterbatch-based productions, replaced-phase raw ingredients are skipped and
     * the produced masterbatch lot is consumed instead.
     */
    public function consumeForFinishedProduction(Production $production): void
    {
        if ($production->status !== ProductionStatus::Finished) {
            return;
        }

        DB::transaction(function () use ($production): void {
            /** @var Production $lockedProduction */
            $lockedProduction = Production::query()
                ->with([
                    'productionItems',
                    'masterbatchLot.producedSupply',
                ])
                ->lockForUpdate()
                ->findOrFail($production->id);

            if ($lockedProduction->status !== ProductionStatus::Finished) {
                return;
            }

            $consumptionsBySupply = [];

            $masterbatch = $lockedProduction->masterbatchLot;
            $replacedPhase = null;

            if ($masterbatch && filled($masterbatch->replaces_phase)) {
                $replacedPhase = $this->normalizePhase((string) $masterbatch->replaces_phase);
            }

            foreach ($lockedProduction->productionItems as $item) {
                if ($item->supply_id === null) {
                    continue;
                }

                if ($replacedPhase !== null && (string) $item->phase === $replacedPhase) {
                    continue;
                }

                $quantity = $item->getCalculatedQuantityKg();

                if ($quantity <= 0) {
                    continue;
                }

                $consumptionsBySupply[$item->supply_id] = ($consumptionsBySupply[$item->supply_id] ?? 0) + $quantity;
            }

            $masterbatchLine = $this->masterbatchService->getMasterbatchLine($lockedProduction);

            if ($masterbatchLine !== null) {
                $masterbatchSupplyId = $masterbatch?->producedSupply?->id;
                $masterbatchQuantity = (float) ($masterbatchLine['quantity'] ?? 0);

                if ($masterbatchSupplyId && $masterbatchQuantity > 0) {
                    $consumptionsBySupply[$masterbatchSupplyId] = ($consumptionsBySupply[$masterbatchSupplyId] ?? 0) + $masterbatchQuantity;
                }
            }

            foreach ($consumptionsBySupply as $supplyId => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                $this->consumeSupply($lockedProduction, (int) $supplyId, round((float) $quantity, 3));
            }
        }, attempts: 5);
    }

    /**
     * Applies one supply deduction only once for a production.
     */
    private function consumeSupply(Production $production, int $supplyId, float $quantityKg): void
    {
        $alreadyConsumed = SuppliesMovement::query()
            ->where('production_id', $production->id)
            ->where('supply_id', $supplyId)
            ->where('movement_type', 'out')
            ->where('reason', 'Consumed in finished production')
            ->exists();

        if ($alreadyConsumed) {
            return;
        }

        /** @var Supply $supply */
        $supply = Supply::query()
            ->lockForUpdate()
            ->findOrFail($supplyId);

        $stockIn = (float) ($supply->quantity_in ?? $supply->initial_quantity ?? 0);
        $newQuantityOut = round((float) ($supply->quantity_out ?? 0) + $quantityKg, 3);
        $newAllocated = max(0, round((float) ($supply->allocated_quantity ?? 0) - $quantityKg, 3));
        $remaining = round($stockIn - $newQuantityOut, 3);

        $supply->update([
            'quantity_out' => $newQuantityOut,
            'allocated_quantity' => $newAllocated,
            'is_in_stock' => $remaining > 0,
        ]);

        $this->inventoryMovementService->recordOutboundToProduction(
            supply: $supply,
            production: $production,
            quantityKg: $quantityKg,
            reason: 'Consumed in finished production',
        );
    }

    /**
     * Maps replacement aliases to internal phase identifiers.
     */
    private function normalizePhase(string $phase): ?string
    {
        return match ($phase) {
            'saponified_oils' => '10',
            'lye' => '20',
            'additives' => '30',
            default => null,
        };
    }
}
