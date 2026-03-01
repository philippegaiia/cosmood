<?php

namespace App\Services\Production;

use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manages supply allocations for production items.
 *
 * This service replaces the previous allocation/reservation/consumption services
 * with a simpler model based on explicit allocation records.
 *
 * Key concepts:
 * - An item can have multiple allocations from different supplies
 * - Each allocation has a status: reserved, consumed, or released
 * - Supply availability is computed from active allocations (not stored)
 */
class ProductionAllocationService
{
    /**
     * Allocate a quantity from a supply to a production item.
     *
     * @throws \InvalidArgumentException if supply has insufficient stock
     */
    public function allocate(
        ProductionItem $item,
        Supply $supply,
        ?float $quantity = null,
    ): ProductionItemAllocation {
        $required = $quantity ?? $item->getUnallocatedQuantity();

        if ($required <= 0) {
            throw new \InvalidArgumentException('Item is already fully allocated.');
        }

        $available = $supply->getAvailableQuantity();

        if ($available <= 0) {
            throw new \InvalidArgumentException("Supply {$supply->batch_number} has no available quantity.");
        }

        $allocateQuantity = min($required, $available);
        $allocateQuantity = round($allocateQuantity, 3);

        $allocation = DB::transaction(function () use ($item, $supply, $allocateQuantity): ProductionItemAllocation {
            $allocation = ProductionItemAllocation::create([
                'production_item_id' => $item->id,
                'supply_id' => $supply->id,
                'quantity' => $allocateQuantity,
                'status' => 'reserved',
                'reserved_at' => now(),
            ]);

            $item->updateAllocationStatus();

            return $allocation;
        });

        return $allocation;
    }

    /**
     * Allocate with automatic partial allocation if supply is insufficient.
     *
     * Returns all allocations created (may be just one if supply is sufficient).
     *
     * @return Collection<ProductionItemAllocation>
     */
    public function allocatePartial(ProductionItem $item, Supply $supply): Collection
    {
        $allocations = collect();

        $available = $supply->getAvailableQuantity();

        if ($available <= 0) {
            return $allocations;
        }

        $allocation = $this->allocate($item, $supply);
        $allocations->push($allocation);

        return $allocations;
    }

    /**
     * Release a specific allocation.
     */
    public function release(ProductionItemAllocation $allocation): void
    {
        if (! $allocation->isReserved()) {
            throw new \InvalidArgumentException('Only reserved allocations can be released.');
        }

        DB::transaction(function () use ($allocation): void {
            $allocation->update(['status' => 'released']);

            $allocation->productionItem->updateAllocationStatus();
        });
    }

    /**
     * Release all allocations for a production item.
     */
    public function releaseAll(ProductionItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $item->allocations()
                ->where('status', 'reserved')
                ->update(['status' => 'released']);

            $item->updateAllocationStatus();
        });
    }

    /**
     * Convert reserved allocations to consumed.
     *
     * This is called when production status changes to ongoing/finished.
     * Also updates supply.quantity_out for stock tracking.
     */
    public function consume(ProductionItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $allocations = $item->allocations()->where('status', 'reserved')->get();

            foreach ($allocations as $allocation) {
                $allocation->update([
                    'status' => 'consumed',
                    'consumed_at' => now(),
                ]);

                $allocation->supply->increment('quantity_out', $allocation->quantity);
            }
        });
    }

    /**
     * Consume all items for a production based on lifecycle rules.
     *
     * - Ongoing: consume non-packaging items
     * - Finished: consume all items
     */
    public function consumeForProduction(Production $production, bool $includePackaging = true, bool $includeNonPackaging = true): void
    {
        $production->productionItems()
            ->when(! $includePackaging, fn ($q) => $q->where('phase', '!=', '40'))
            ->when(! $includeNonPackaging, fn ($q) => $q->where('phase', '40'))
            ->each(fn ($item) => $this->consume($item));
    }

    /**
     * Release all allocations for a production.
     *
     * Called when production is cancelled.
     */
    public function releaseForProduction(Production $production): void
    {
        $production->productionItems->each(fn ($item) => $this->releaseAll($item));
    }

    /**
     * Get available supplies for an ingredient, ordered by expiry date (FIFO).
     *
     * @return Collection<int, array{id: int, batch_number: string, available: float, expiry_date: string|null, delivery_date: string|null}>
     */
    public function getAvailableSupplies(Ingredient $ingredient): Collection
    {
        return Supply::query()
            ->whereHas('supplierListing', fn ($q) => $q->where('ingredient_id', $ingredient->id))
            ->where('is_in_stock', true)
            ->orderBy('expiry_date')
            ->get()
            ->filter(fn (Supply $supply) => $supply->getAvailableQuantity() > 0)
            ->map(fn (Supply $supply) => [
                'id' => $supply->id,
                'batch_number' => $supply->batch_number,
                'available' => round($supply->getAvailableQuantity(), 3),
                'expiry_date' => $supply->expiry_date?->format('Y-m-d'),
                'delivery_date' => $supply->delivery_date?->format('Y-m-d'),
            ])
            ->values();
    }

    /**
     * Preview what an allocation would look like.
     *
     * @return array{required: float, available: float, can_fulfill: bool, shortage: float}
     */
    public function preview(ProductionItem $item, Supply $supply): array
    {
        $required = $item->getUnallocatedQuantity();
        $available = $supply->getAvailableQuantity();

        return [
            'required' => round($required, 3),
            'available' => round($available, 3),
            'can_fulfill' => round($available, 3) >= round($required, 3),
            'shortage' => round(max(0, $required - $available), 3),
        ];
    }

    /**
     * Get the total available quantity for an ingredient across all supplies.
     */
    public function getTotalAvailable(Ingredient $ingredient): float
    {
        return (float) Supply::query()
            ->whereHas('supplierListing', fn ($q) => $q->where('ingredient_id', $ingredient->id))
            ->where('is_in_stock', true)
            ->get()
            ->sum(fn (Supply $supply) => $supply->getAvailableQuantity());
    }

    /**
     * Check if an ingredient has sufficient stock for a required quantity.
     */
    public function hasSufficientStock(Ingredient $ingredient, float $requiredQuantity): bool
    {
        return round($this->getTotalAvailable($ingredient), 3) >= round($requiredQuantity, 3);
    }
}
