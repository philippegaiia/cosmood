<?php

namespace App\Services\Production;

use App\Models\Production\Production;
use App\Models\Supply\SuppliesMovement;
use App\Models\Supply\Supply;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synchronizes stock reservations linked to one production batch.
 *
 * Reservation entries reduce supply availability via `allocated_quantity` and are tracked
 * in `supplies_movements` so they can be rebuilt safely whenever production items change.
 */
class ProductionReservationService
{
    public const RESERVATION_MOVEMENT_TYPE = 'reservation';

    public const RESERVATION_REASON = 'Reserved for production';

    public function __construct(
        private readonly MasterbatchService $masterbatchService,
    ) {}

    /**
     * Reserves all selected inputs (packaging and non-packaging).
     */
    public function syncForPlanning(Production $production): void
    {
        $this->syncReservations(
            production: $production,
            includePackaging: true,
            includeNonPackaging: true,
        );
    }

    /**
     * Keeps only packaging inputs reserved once production has started.
     */
    public function syncForOngoing(Production $production): void
    {
        $this->syncReservations(
            production: $production,
            includePackaging: true,
            includeNonPackaging: false,
        );
    }

    /**
     * Releases all reservations currently linked to the production.
     */
    public function releaseForProduction(Production $production): void
    {
        DB::transaction(function () use ($production): void {
            /** @var Production|null $lockedProduction */
            $lockedProduction = Production::query()
                ->lockForUpdate()
                ->find($production->id);

            if (! $lockedProduction) {
                return;
            }

            $this->rollbackPreviousReservations($lockedProduction);
        }, attempts: 5);
    }

    /**
     * Rebuilds reservations from current production-item supply links.
     */
    private function syncReservations(
        Production $production,
        bool $includePackaging,
        bool $includeNonPackaging,
    ): void {
        DB::transaction(function () use ($production, $includePackaging, $includeNonPackaging): void {
            /** @var Production $lockedProduction */
            $lockedProduction = Production::query()
                ->with([
                    'productionItems.ingredient',
                    'masterbatchLot.producedSupply',
                ])
                ->lockForUpdate()
                ->findOrFail($production->id);

            $this->rollbackPreviousReservations($lockedProduction);

            $reservationsBySupply = $this->resolveReservationsBySupply(
                production: $lockedProduction,
                includePackaging: $includePackaging,
                includeNonPackaging: $includeNonPackaging,
            );

            foreach ($reservationsBySupply as $supplyId => $quantityKg) {
                if ($quantityKg <= 0) {
                    continue;
                }

                $this->reserveSupply(
                    production: $lockedProduction,
                    supplyId: (int) $supplyId,
                    quantityKg: round((float) $quantityKg, 3),
                );
            }
        }, attempts: 5);
    }

    /**
     * Computes desired reservation quantities by supply lot for one lifecycle stage.
     *
     * Masterbatch replacement keeps replaced raw oils unreserved and reserves only the
     * selected masterbatch output lot when needed.
     *
     * @return array<int, float>
     */
    private function resolveReservationsBySupply(
        Production $production,
        bool $includePackaging,
        bool $includeNonPackaging,
    ): array {
        $reservationsBySupply = [];
        $masterbatch = $production->masterbatchLot;
        $replacedPhase = null;

        if ($masterbatch && filled($masterbatch->replaces_phase)) {
            $replacedPhase = $this->normalizePhase((string) $masterbatch->replaces_phase);
        }

        foreach ($production->productionItems as $item) {
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

            $quantityKg = $item->getCalculatedQuantityKg($production);

            if ($quantityKg <= 0) {
                continue;
            }

            $reservationsBySupply[$item->supply_id] = ($reservationsBySupply[$item->supply_id] ?? 0) + $quantityKg;
        }

        $masterbatchLine = $this->masterbatchService->getMasterbatchLine($production);

        if ($includeNonPackaging && $masterbatchLine !== null) {
            $masterbatchSupplyId = $masterbatch?->producedSupply?->id;
            $masterbatchQuantity = (float) ($masterbatchLine['quantity'] ?? 0);

            if ($masterbatchSupplyId && $masterbatchQuantity > 0) {
                $reservationsBySupply[$masterbatchSupplyId] = ($reservationsBySupply[$masterbatchSupplyId] ?? 0) + $masterbatchQuantity;
            }
        }

        return $reservationsBySupply;
    }

    /**
     * Applies one reservation lot update and stores its movement trace.
     */
    private function reserveSupply(Production $production, int $supplyId, float $quantityKg): void
    {
        /** @var Supply $supply */
        $supply = Supply::query()
            ->lockForUpdate()
            ->findOrFail($supplyId);

        $availableQuantity = $supply->getAvailableQuantity();

        if ($quantityKg > $availableQuantity) {
            throw new RuntimeException(sprintf(
                'Cannot reserve %.3f from lot %s: only %.3f available.',
                $quantityKg,
                (string) ($supply->batch_number ?? '#'.$supply->id),
                $availableQuantity,
            ));
        }

        $supply->update([
            'allocated_quantity' => round((float) ($supply->allocated_quantity ?? 0) + $quantityKg, 3),
        ]);

        SuppliesMovement::query()->create([
            'supply_id' => $supply->id,
            'supplier_order_item_id' => $supply->supplier_order_item_id,
            'production_id' => $production->id,
            'user_id' => null,
            'movement_type' => self::RESERVATION_MOVEMENT_TYPE,
            'quantity' => $quantityKg,
            'unit' => $supply->supplierListing?->unit_of_measure ?: 'kg',
            'reason' => self::RESERVATION_REASON,
            'meta' => [
                'production_batch' => $production->getLotIdentifier(),
                'supply_batch' => $supply->batch_number,
            ],
            'moved_at' => now(),
        ]);
    }

    /**
     * Removes previously computed reservations before rebuilding from current state.
     */
    private function rollbackPreviousReservations(Production $production): void
    {
        $movements = SuppliesMovement::query()
            ->where('production_id', $production->id)
            ->where('movement_type', self::RESERVATION_MOVEMENT_TYPE)
            ->where('reason', self::RESERVATION_REASON)
            ->lockForUpdate()
            ->get();

        foreach ($movements as $movement) {
            if (! $movement->supply_id) {
                $movement->delete();

                continue;
            }

            $supply = Supply::query()
                ->lockForUpdate()
                ->find($movement->supply_id);

            if ($supply) {
                $supply->update([
                    'allocated_quantity' => max(0, round((float) ($supply->allocated_quantity ?? 0) - (float) $movement->quantity, 3)),
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
