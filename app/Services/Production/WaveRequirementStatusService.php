<?php

namespace App\Services\Production;

use App\Enums\OrderStatus;
use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionWave;
use App\Models\Supply\SupplierOrderItem;
use Illuminate\Support\Collection;

class WaveRequirementStatusService
{
    public function syncForWave(ProductionWave $wave): void
    {
        $items = $this->getWaveProductionItems($wave);

        if ($items->isEmpty()) {
            return;
        }

        $orderedByIngredient = $this->getOrderedQuantitiesByIngredient($wave);
        $receivedByIngredient = $this->getReceivedQuantitiesByIngredient($wave);

        $items
            ->groupBy('ingredient_id')
            ->each(function (Collection $ingredientItems, int|string $ingredientId) use ($orderedByIngredient, $receivedByIngredient): void {
                $remainingOrdered = (float) ($orderedByIngredient->get((int) $ingredientId) ?? 0);
                $remainingReceived = (float) ($receivedByIngredient->get((int) $ingredientId) ?? 0);

                foreach ($ingredientItems as $item) {
                    $remainingRequiredQuantity = round($item->getUnallocatedQuantity(), 3);

                    if ($remainingRequiredQuantity <= 0) {
                        if ($item->procurement_status !== ProcurementStatus::Received) {
                            $item->update(['procurement_status' => ProcurementStatus::Received]);
                        }

                        continue;
                    }

                    $nextStatus = ProcurementStatus::NotOrdered;
                    $isCoveredByReceivedCommitment = $remainingReceived >= $remainingRequiredQuantity;
                    $isCoveredByOrderedCommitment = $remainingOrdered >= $remainingRequiredQuantity;

                    if ($isCoveredByReceivedCommitment) {
                        $nextStatus = ProcurementStatus::Received;
                    } elseif ($isCoveredByOrderedCommitment) {
                        $nextStatus = ProcurementStatus::Ordered;
                    } elseif ($item->is_order_marked) {
                        $nextStatus = ProcurementStatus::Ordered;
                    }

                    if ($item->procurement_status !== $nextStatus) {
                        $item->update(['procurement_status' => $nextStatus]);
                    }

                    if (! $isCoveredByReceivedCommitment && ! $isCoveredByOrderedCommitment) {
                        continue;
                    }

                    $remainingOrdered = max(0, $remainingOrdered - $remainingRequiredQuantity);
                    $remainingReceived = max(0, $remainingReceived - min($remainingReceived, $remainingRequiredQuantity));
                }
            });
    }

    /**
     * @return array<int, string>
     */
    public function getIngredientOptionsForWave(ProductionWave $wave): array
    {
        return $this->getWaveProductionItems($wave)
            ->groupBy('ingredient_id')
            ->map(function (Collection $items, int|string $ingredientId): string {
                $ingredientName = (string) ($items->first()?->ingredient?->name ?: __('Ingrédient #:id', ['id' => $ingredientId]));

                return $ingredientName;
            })
            ->sortBy(fn (string $name): string => mb_strtolower($name))
            ->mapWithKeys(fn (string $name, int|string $ingredientId): array => [(int) $ingredientId => $name])
            ->all();
    }

    /**
     * @param  array<int, int|string>  $ingredientIds
     */
    public function markNotOrderedItemsAsOrderedForIngredients(ProductionWave $wave, array $ingredientIds): int
    {
        $normalizedIngredientIds = collect($ingredientIds)
            ->map(fn (mixed $ingredientId): int => (int) $ingredientId)
            ->filter(fn (int $ingredientId): bool => $ingredientId > 0)
            ->unique()
            ->values();

        if ($normalizedIngredientIds->isEmpty()) {
            return 0;
        }

        $itemsToMark = $this->getWaveProductionItems($wave)
            ->whereIn('ingredient_id', $normalizedIngredientIds->all())
            ->filter(fn (ProductionItem $item): bool => $item->procurement_status === ProcurementStatus::NotOrdered && ! $item->isFullyAllocated());

        $updatedCount = 0;

        foreach ($itemsToMark as $item) {
            $item->update([
                'is_order_marked' => true,
                'procurement_status' => ProcurementStatus::Ordered,
            ]);

            $updatedCount++;
        }

        if ($updatedCount > 0) {
            $this->syncForWave($wave);
        }

        return $updatedCount;
    }

    public function markItemsAsOrderedFromPlacedWaveOrders(ProductionWave $wave): int
    {
        $orderedByIngredient = $this->getOrderedQuantitiesByIngredient($wave);

        if ($orderedByIngredient->isEmpty()) {
            return 0;
        }

        $updatedCount = 0;

        $this->getWaveProductionItems($wave)
            ->groupBy('ingredient_id')
            ->each(function (Collection $ingredientItems, int|string $ingredientId) use ($orderedByIngredient, &$updatedCount): void {
                $remainingCommittedQuantity = round((float) ($orderedByIngredient->get((int) $ingredientId) ?? 0), 3);

                if ($remainingCommittedQuantity <= 0) {
                    return;
                }

                foreach ($ingredientItems as $item) {
                    if ($item->is_order_marked || $item->isFullyAllocated()) {
                        continue;
                    }

                    $remainingRequiredQuantity = round($item->getUnallocatedQuantity(), 3);

                    if ($remainingRequiredQuantity <= 0 || $remainingCommittedQuantity < $remainingRequiredQuantity) {
                        continue;
                    }

                    $item->update([
                        'is_order_marked' => true,
                        'procurement_status' => $item->procurement_status === ProcurementStatus::Received
                            ? ProcurementStatus::Received
                            : ProcurementStatus::Ordered,
                    ]);

                    $remainingCommittedQuantity = max(0, $remainingCommittedQuantity - $remainingRequiredQuantity);
                    $updatedCount++;
                }
            });

        if ($updatedCount > 0) {
            $this->syncForWave($wave);
        }

        return $updatedCount;
    }

    /**
     * @param  array<int, int|string>  $ingredientIds
     */
    public function markNotOrderedItemsAsOrderedForProductionIngredients(Production $production, array $ingredientIds): int
    {
        $normalizedIngredientIds = collect($ingredientIds)
            ->map(fn (mixed $ingredientId): int => (int) $ingredientId)
            ->filter(fn (int $ingredientId): bool => $ingredientId > 0)
            ->unique()
            ->values();

        if ($normalizedIngredientIds->isEmpty()) {
            return 0;
        }

        $itemsToMark = $this->getProductionItems($production)
            ->whereIn('ingredient_id', $normalizedIngredientIds->all())
            ->filter(fn (ProductionItem $item): bool => $item->procurement_status === ProcurementStatus::NotOrdered && ! $item->isFullyAllocated());

        $updatedCount = 0;

        foreach ($itemsToMark as $item) {
            $item->update([
                'is_order_marked' => true,
                'procurement_status' => ProcurementStatus::Ordered,
            ]);

            $updatedCount++;
        }

        if ($updatedCount > 0 && $production->wave) {
            $this->syncForWave($production->wave);
        }

        return $updatedCount;
    }

    private function getWaveProductionItems(ProductionWave $wave): Collection
    {
        $wave->loadMissing([
            'productions.productionItems.ingredient',
            'productions.productionItems.allocations',
            'productions.productionItems.production',
            'productions.masterbatchLot',
        ]);

        $activeProductions = $wave->productions
            ->filter(fn (Production $production): bool => $production->status !== ProductionStatus::Cancelled);

        $items = collect();

        foreach ($activeProductions as $production) {
            $replacedPhase = $production->masterbatch_lot_id
                ? Phases::normalize($production->masterbatchLot?->replaces_phase)
                : null;

            $productionItems = $production->productionItems
                ->when($replacedPhase !== null, fn ($items) => $items->where('phase', '!=', $replacedPhase));

            $items = $items->merge($productionItems);
        }

        return $items->sortBy(fn (ProductionItem $item): string => ($item->production?->production_date?->format('Y-m-d') ?? '9999-12-31').'-'.str_pad((string) $item->id, 10, '0', STR_PAD_LEFT))->values();
    }

    private function getProductionItems(Production $production): Collection
    {
        $production = $production->fresh([
            'productionItems.ingredient',
            'productionItems.allocations',
            'masterbatchLot',
            'wave',
        ]) ?? $production;

        if ($production->status === ProductionStatus::Cancelled) {
            return collect();
        }

        $replacedPhase = $production->masterbatch_lot_id
            ? Phases::normalize($production->masterbatchLot?->replaces_phase)
            : null;

        return $production->productionItems
            ->each(fn (ProductionItem $item): ProductionItem => $item->setRelation('production', $production))
            ->when($replacedPhase !== null, fn ($items) => $items->where('phase', '!=', $replacedPhase))
            ->sortBy(fn (ProductionItem $item): string => str_pad((string) ($item->sort ?? $item->id), 10, '0', STR_PAD_LEFT).'-'.str_pad((string) $item->id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    private function getOrderedQuantitiesByIngredient(ProductionWave $wave): Collection
    {
        return SupplierOrderItem::query()
            ->whereHas('supplierOrder', function ($query) use ($wave): void {
                $query
                    ->where('production_wave_id', $wave->id)
                    ->whereIn('order_status', OrderStatus::placedStatuses());
            })
            ->with('supplierListing:id,ingredient_id')
            ->get()
            ->groupBy(fn (SupplierOrderItem $item): ?int => $item->supplierListing?->ingredient_id)
            ->map(fn (Collection $items): float => (float) $items->sum(fn (SupplierOrderItem $item): float => $this->getCommittedWaveQuantityKg($item)))
            ->filter(fn (float $quantity, $ingredientId): bool => $ingredientId !== null && $quantity > 0)
            ->mapWithKeys(fn (float $quantity, $ingredientId): array => [(int) $ingredientId => $quantity]);
    }

    private function getReceivedQuantitiesByIngredient(ProductionWave $wave): Collection
    {
        return SupplierOrderItem::query()
            ->whereHas('supplierOrder', function ($query) use ($wave): void {
                $query->where('production_wave_id', $wave->id);
            })
            ->whereHas('supply')
            ->with([
                'supplierListing:id,ingredient_id',
                'supply:id,supplier_order_item_id,initial_quantity,quantity_in',
            ])
            ->get()
            ->groupBy(fn (SupplierOrderItem $item): ?int => $item->supplierListing?->ingredient_id)
            ->map(fn (Collection $items): float => (float) $items->sum(fn (SupplierOrderItem $item): float => $this->getReceivedCommittedWaveQuantityKg($item)))
            ->filter(fn (float $quantity, $ingredientId): bool => $ingredientId !== null && $quantity > 0)
            ->mapWithKeys(fn (float $quantity, $ingredientId): array => [(int) $ingredientId => $quantity]);
    }

    /**
     * Wave-linked procurement statuses must consume only the explicit PO
     * commitment for that wave. Any excess ordered quantity remains available in
     * planning as a shared provisional pool, but it must not silently mark the
     * linked wave as ordered.
     */
    private function getCommittedWaveQuantityKg(SupplierOrderItem $item): float
    {
        return round(max(0, min(
            (float) ($item->committed_quantity_kg ?? 0),
            $item->getOrderedQuantityKg(),
        )), 3);
    }

    /**
     * Received coverage for a linked wave is capped by the committed quantity on
     * the originating PO line. Extra stock received into inventory from a large
     * pack size stays available via stock/advisory flows until it is allocated.
     */
    private function getReceivedCommittedWaveQuantityKg(SupplierOrderItem $item): float
    {
        $receivedQuantityKg = (float) ($item->supply?->quantity_in ?? $item->supply?->initial_quantity ?? 0);

        return round(min($receivedQuantityKg, $this->getCommittedWaveQuantityKg($item)), 3);
    }
}
