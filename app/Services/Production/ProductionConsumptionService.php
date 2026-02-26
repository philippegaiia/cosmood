<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Supply\SuppliesMovement;
use App\Models\Supply\Supply;
use App\Services\InventoryMovementService;
use Illuminate\Support\Facades\DB;

/**
 * Converts production lifecycle stages into stock outbound movements.
 */
class ProductionConsumptionService
{
    public const REASON_ONGOING_CONSUMPTION = 'Consumed at production start';

    public const REASON_FINISHED_PACKAGING_CONSUMPTION = 'Consumed at production finish (packaging)';

    public function __construct(
        private readonly InventoryMovementService $inventoryMovementService,
        private readonly MasterbatchService $masterbatchService,
    ) {}

    /**
     * Consumes non-packaging inputs when production starts.
     *
     * This method is idempotent: previous start consumptions are rolled back
     * before recalculating from current production items.
     */
    public function consumeForOngoingProduction(Production $production): void
    {
        if (! in_array($production->status, [ProductionStatus::Ongoing, ProductionStatus::Finished], true)) {
            return;
        }

        $this->consumeForProductionStage(
            production: $production,
            includePackaging: false,
            includeNonPackaging: true,
            movementReason: self::REASON_ONGOING_CONSUMPTION,
        );
    }

    /**
     * Consumes packaging inputs when production is finished.
     *
     * This method is idempotent: previous finish-packaging consumptions are rolled back
     * before recalculating from current production items.
     */
    public function consumeForFinishedProduction(Production $production): void
    {
        if ($production->status !== ProductionStatus::Finished) {
            return;
        }

        $this->consumeForProductionStage(
            production: $production,
            includePackaging: true,
            includeNonPackaging: false,
            movementReason: self::REASON_FINISHED_PACKAGING_CONSUMPTION,
        );
    }

    /**
     * Reverts all computed production consumption movements for one batch.
     */
    public function rollbackAllComputedConsumption(Production $production): void
    {
        DB::transaction(function () use ($production): void {
            /** @var Production|null $lockedProduction */
            $lockedProduction = Production::query()
                ->lockForUpdate()
                ->find($production->id);

            if (! $lockedProduction) {
                return;
            }

            $this->rollbackPreviousConsumption($lockedProduction, self::REASON_ONGOING_CONSUMPTION);
            $this->rollbackPreviousConsumption($lockedProduction, self::REASON_FINISHED_PACKAGING_CONSUMPTION);
        }, attempts: 5);
    }

    /**
     * Applies one consumption stage according to phase inclusion rules.
     *
     * For masterbatch-based productions, replaced-phase raw ingredients are skipped and
     * the produced masterbatch lot is consumed instead for non-packaging stage.
     */
    private function consumeForProductionStage(
        Production $production,
        bool $includePackaging,
        bool $includeNonPackaging,
        string $movementReason,
    ): void {
        DB::transaction(function () use ($production, $includePackaging, $includeNonPackaging, $movementReason): void {
            /** @var Production $lockedProduction */
            $lockedProduction = Production::query()
                ->with([
                    'productionItems.ingredient',
                    'masterbatchLot.producedSupply',
                ])
                ->lockForUpdate()
                ->findOrFail($production->id);

            if (! in_array($lockedProduction->status, [ProductionStatus::Ongoing, ProductionStatus::Finished], true)) {
                return;
            }

            $this->rollbackPreviousConsumption($lockedProduction, $movementReason);

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

                $isPackagingPhase = $item->isPackagingPhase();

                if ($isPackagingPhase && ! $includePackaging) {
                    continue;
                }

                if (! $isPackagingPhase && ! $includeNonPackaging) {
                    continue;
                }

                if ($replacedPhase !== null && (string) $item->phase === $replacedPhase) {
                    continue;
                }

                $quantity = $item->getCalculatedQuantityKg($lockedProduction);

                if ($quantity <= 0) {
                    continue;
                }

                $consumptionsBySupply[$item->supply_id] = ($consumptionsBySupply[$item->supply_id] ?? 0) + $quantity;
            }

            $masterbatchLine = $this->masterbatchService->getMasterbatchLine($lockedProduction);

            if ($includeNonPackaging && $masterbatchLine !== null) {
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

                $this->consumeSupply(
                    production: $lockedProduction,
                    supplyId: (int) $supplyId,
                    quantityKg: round((float) $quantity, 3),
                    movementReason: $movementReason,
                );
            }
        }, attempts: 5);
    }

    /**
     * Applies one supply deduction only once for a production.
     */
    private function consumeSupply(Production $production, int $supplyId, float $quantityKg, string $movementReason): void
    {
        /** @var Supply $supply */
        $supply = Supply::query()
            ->lockForUpdate()
            ->findOrFail($supplyId);

        $stockIn = (float) ($supply->quantity_in ?? $supply->initial_quantity ?? 0);
        $newQuantityOut = round((float) ($supply->quantity_out ?? 0) + $quantityKg, 3);
        $remaining = round($stockIn - $newQuantityOut, 3);

        $supply->update([
            'quantity_out' => $newQuantityOut,
            'is_in_stock' => $remaining > 0,
        ]);

        $this->inventoryMovementService->recordOutboundToProduction(
            supply: $supply,
            production: $production,
            quantityKg: $quantityKg,
            reason: $movementReason,
        );
    }

    /**
     * Reverts previous computed consumption movements before recalculating from current items.
     */
    private function rollbackPreviousConsumption(Production $production, string $movementReason): void
    {
        $movements = SuppliesMovement::query()
            ->where('production_id', $production->id)
            ->where('movement_type', 'out')
            ->where('reason', $movementReason)
            ->lockForUpdate()
            ->get();

        foreach ($movements as $movement) {
            if (! $movement->supply_id) {
                continue;
            }

            $supply = Supply::query()
                ->lockForUpdate()
                ->find($movement->supply_id);

            if ($supply) {
                $stockIn = (float) ($supply->quantity_in ?? $supply->initial_quantity ?? 0);
                $newQuantityOut = max(0, round((float) ($supply->quantity_out ?? 0) - (float) $movement->quantity, 3));
                $remaining = round($stockIn - $newQuantityOut, 3);

                $supply->update([
                    'quantity_out' => $newQuantityOut,
                    'is_in_stock' => $remaining > 0,
                ]);
            }

            $movement->delete();
        }
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
