<?php

namespace App\Services\Production;

use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Settings;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use App\Services\InventoryMovementService;
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
 * - Stock movements are created for audit trail (positive for allocation, negative for release)
 * - Supply allocated_quantity is calculated from sum of allocation movements
 */
class ProductionAllocationService
{
    public function __construct(
        private readonly InventoryMovementService $movementService,
        private readonly WaveRequirementStatusService $waveRequirementStatusService,
    ) {}

    /**
     * Allocate a quantity from a supply to a production item.
     *
     * Uses double-entry accounting: creates a positive allocation movement.
     * Validates that supply is in stock before allocating.
     *
     * Side effects:
     * - Creates allocation movement record
     * - Updates item allocation status
     *
     * @throws \InvalidArgumentException if supply is out of stock or has insufficient quantity
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

        if (! $supply->is_in_stock) {
            throw new \InvalidArgumentException("Supply {$supply->batch_number} is out of stock and cannot be allocated.");
        }

        $available = $supply->getAvailableQuantity();

        if ($available <= 0) {
            throw new \InvalidArgumentException("Supply {$supply->batch_number} has no available quantity.");
        }

        $allocateQuantity = min($required, $available);
        $allocateQuantity = round($allocateQuantity, 3);

        $allocation = DB::transaction(function () use ($item, $supply, $allocateQuantity): ProductionItemAllocation {
            // Check for existing allocation
            $existingAllocation = ProductionItemAllocation::query()
                ->where('production_item_id', $item->id)
                ->where('supply_id', $supply->id)
                ->first();

            if ($existingAllocation) {
                if ($existingAllocation->status === 'released') {
                    // Reuse released allocation
                    $existingAllocation->update([
                        'status' => 'reserved',
                        'quantity' => $allocateQuantity,
                        'reserved_at' => now(),
                    ]);

                    $this->movementService->recordAllocation(
                        supply: $supply,
                        production: $item->production,
                        quantityKg: $allocateQuantity,
                    );

                    $item->updateAllocationStatus();

                    return $existingAllocation;
                }

                throw new \InvalidArgumentException('Supply already allocated to this item.');
            }

            // Create new allocation
            $allocation = ProductionItemAllocation::create([
                'production_item_id' => $item->id,
                'supply_id' => $supply->id,
                'quantity' => $allocateQuantity,
                'status' => 'reserved',
                'reserved_at' => now(),
            ]);

            $this->movementService->recordAllocation(
                supply: $supply,
                production: $item->production,
                quantityKg: $allocateQuantity,
            );

            $item->updateAllocationStatus();

            return $allocation;
        });

        $this->syncWaveRequirementStatusesForItem($item);

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

        // Eager load relationships to prevent lazy loading violation
        $allocation->loadMissing(['supply', 'productionItem.production']);

        DB::transaction(function () use ($allocation): void {
            $supply = $allocation->supply;
            $item = $allocation->productionItem;
            $production = $item->production;
            $quantity = $allocation->quantity;

            $this->movementService->recordRelease(
                supply: $supply,
                production: $production,
                quantityKg: $quantity,
            );

            $allocation->update(['status' => 'released']);
            $item->updateAllocationStatus();
        });

        $this->syncWaveRequirementStatusesForItem($allocation->productionItem);
    }

    /**
     * Release all allocations for a production item.
     */
    public function releaseAll(ProductionItem $item, bool $syncWaveRequirements = true): void
    {
        // Eager load production relationship to prevent lazy loading violation
        $item->loadMissing('production');

        DB::transaction(function () use ($item): void {
            $allocations = $item->allocations()
                ->where('status', 'reserved')
                ->with('supply')
                ->get();

            foreach ($allocations as $allocation) {
                $supply = $allocation->supply;
                $production = $item->production;
                $quantity = $allocation->quantity;

                $this->movementService->recordRelease(
                    supply: $supply,
                    production: $production,
                    quantityKg: $quantity,
                );
            }

            $item->allocations()
                ->where('status', 'reserved')
                ->update(['status' => 'released']);

            $item->updateAllocationStatus();
        });

        if ($syncWaveRequirements) {
            $this->syncWaveRequirementStatusesForItem($item);
        }
    }

    /**
     * Convert reserved allocations to consumed.
     *
     * This is called when production status changes to ongoing/finished.
     * Creates compensating transactions:
     * 1. Negative allocation movement to release reservation
     * 2. Outbound movement for actual consumption
     *
     * Side effects:
     * - Updates supply.quantity_out
     * - Updates supply.last_used_at timestamp
     * - Marks allocation as consumed
     *
     * @throws \InvalidArgumentException if allocation not found
     */
    public function consume(ProductionItem $item): void
    {
        // Eager load relationships to prevent lazy loading violation
        $item->loadMissing('production');

        DB::transaction(function () use ($item): void {
            $allocations = $item->allocations()
                ->where('status', 'reserved')
                ->with('supply')
                ->get();

            foreach ($allocations as $allocation) {
                $supply = $allocation->supply;
                $production = $item->production;
                $quantity = $allocation->quantity;

                // Create negative allocation to cancel out the reservation
                $this->movementService->recordRelease(
                    supply: $supply,
                    production: $production,
                    quantityKg: $quantity,
                    reason: 'Consumed in production - releasing reservation',
                );

                // Create consumption movement
                $this->movementService->recordOutboundToProduction(
                    supply: $supply,
                    production: $production,
                    quantityKg: $quantity,
                    reason: 'Consumed in production',
                );

                $allocation->update([
                    'status' => 'consumed',
                    'consumed_at' => now(),
                ]);

                $supply->increment('quantity_out', $quantity);

                // Update last used timestamp
                $supply->update(['last_used_at' => now()]);
            }
        });

        $this->syncWaveRequirementStatusesForItem($item);
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
        DB::transaction(function () use ($production): void {
            $production->productionItems->each(fn ($item) => $this->releaseAll($item, false));
            $this->movementService->deleteAllAllocationsForProduction($production);
        });

        $this->syncWaveRequirementStatusesForProduction($production);
    }

    /**
     * Creates a new production item for remaining unallocated quantity.
     *
     * @return ProductionItem The new split item
     */
    public function createSplitItem(ProductionItem $originalItem): ProductionItem
    {
        return DB::transaction(function () use ($originalItem): ProductionItem {
            $lockedOriginalItem = ProductionItem::query()
                ->whereKey($originalItem->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedOriginalItem) {
                throw new \InvalidArgumentException('Original item not found.');
            }

            $hasConsumedAllocations = $lockedOriginalItem->allocations()
                ->where('status', 'consumed')
                ->exists();

            if ($hasConsumedAllocations) {
                throw new \InvalidArgumentException('Cannot split an item with consumed allocations.');
            }

            $originalRequiredQuantity = round($this->calculateRequiredQuantity($lockedOriginalItem), 3);

            if ($originalRequiredQuantity <= 0) {
                throw new \InvalidArgumentException('Original item has no required quantity.');
            }

            $allocatedQuantity = round($lockedOriginalItem->getTotalAllocatedQuantity(), 3);

            if ($allocatedQuantity <= 0) {
                throw new \InvalidArgumentException('Cannot split an item without allocated quantity.');
            }

            $unallocatedQuantity = round(max(0, $originalRequiredQuantity - $allocatedQuantity), 3);

            if ($unallocatedQuantity <= 0) {
                throw new \InvalidArgumentException('No unallocated quantity to split.');
            }

            $originalCoefficient = (float) $lockedOriginalItem->percentage_of_oils;
            $newOriginalCoefficient = round(($allocatedQuantity / $originalRequiredQuantity) * $originalCoefficient, 5);
            $splitCoefficient = round($originalCoefficient - $newOriginalCoefficient, 5);

            if ($splitCoefficient <= 0) {
                throw new \InvalidArgumentException('No remaining coefficient to split.');
            }

            $rootItemId = $lockedOriginalItem->split_root_item_id ?? $lockedOriginalItem->id;

            $lockedOriginalItem->update([
                'percentage_of_oils' => $newOriginalCoefficient,
                'required_quantity' => round($this->calculateRequiredQuantity($lockedOriginalItem, $newOriginalCoefficient), 3),
            ]);

            $lockedOriginalItem->updateAllocationStatus();

            $newItem = ProductionItem::create([
                'production_id' => $lockedOriginalItem->production_id,
                'ingredient_id' => $lockedOriginalItem->ingredient_id,
                'supplier_listing_id' => $lockedOriginalItem->supplier_listing_id,
                'phase' => $lockedOriginalItem->phase,
                'percentage_of_oils' => $splitCoefficient,
                'required_quantity' => 0,
                'calculation_mode' => $lockedOriginalItem->calculation_mode,
                'organic' => $lockedOriginalItem->organic,
                'is_supplied' => false,
                'procurement_status' => $lockedOriginalItem->procurement_status,
                'allocation_status' => \App\Enums\AllocationStatus::Unassigned,
                'sort' => $lockedOriginalItem->sort + 1,
                'split_from_item_id' => $lockedOriginalItem->id,
                'split_root_item_id' => $rootItemId,
            ]);

            $newItem->update([
                'required_quantity' => round($this->calculateRequiredQuantity($newItem, $splitCoefficient), 3),
            ]);

            ProductionItem::query()
                ->where('production_id', $lockedOriginalItem->production_id)
                ->where('sort', '>=', $newItem->sort)
                ->where('id', '!=', $newItem->id)
                ->increment('sort');

            return $newItem->fresh();
        });
    }

    /**
     * Merge a split item back into its parent item.
     *
     * @return ProductionItem The parent item with updated coefficient
     */
    public function mergeSplitItem(ProductionItem $splitItem): ProductionItem
    {
        return DB::transaction(function () use ($splitItem): ProductionItem {
            $lockedSplitItem = ProductionItem::query()
                ->whereKey($splitItem->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedSplitItem) {
                throw new \InvalidArgumentException('Split item not found.');
            }

            if (! $lockedSplitItem->isSplitChild()) {
                throw new \InvalidArgumentException('Item is not a split child.');
            }

            $splitHasConsumedAllocations = $lockedSplitItem->allocations()
                ->where('status', 'consumed')
                ->exists();

            if ($splitHasConsumedAllocations) {
                throw new \InvalidArgumentException('Cannot merge a split item with consumed allocations.');
            }

            $mergeTarget = $this->resolveActiveMergeTarget($lockedSplitItem);

            if ($mergeTarget) {
                $targetHasConsumedAllocations = $mergeTarget->allocations()
                    ->where('status', 'consumed')
                    ->exists();

                if ($targetHasConsumedAllocations) {
                    throw new \InvalidArgumentException('Cannot merge into an item with consumed allocations.');
                }
            }

            $this->releaseAll($lockedSplitItem);

            if (! $mergeTarget) {
                $lockedSplitItem->update([
                    'split_from_item_id' => null,
                    'split_root_item_id' => null,
                ]);

                $lockedSplitItem->updateAllocationStatus();

                return $lockedSplitItem;
            }

            $newParentCoefficient = round(
                (float) $mergeTarget->percentage_of_oils + (float) $lockedSplitItem->percentage_of_oils,
                5
            );

            $newRootItemId = $mergeTarget->split_root_item_id ?? $mergeTarget->id;

            ProductionItem::query()
                ->where('split_from_item_id', $lockedSplitItem->id)
                ->update([
                    'split_from_item_id' => $mergeTarget->id,
                    'split_root_item_id' => $newRootItemId,
                ]);

            $mergeTarget->update([
                'percentage_of_oils' => $newParentCoefficient,
                'required_quantity' => round($this->calculateRequiredQuantity($mergeTarget, $newParentCoefficient), 3),
            ]);

            $lockedSplitItem->forceDelete();

            $mergeTarget->updateAllocationStatus();

            return $mergeTarget->fresh();
        });
    }

    private function calculateRequiredQuantity(ProductionItem $item, ?float $coefficient = null): float
    {
        $production = $item->relationLoaded('production')
            ? $item->production
            : Production::query()->select(['id', 'planned_quantity', 'expected_units'])->find($item->production_id);

        $calculator = app(IngredientQuantityCalculator::class);

        return $calculator->calculate(
            coefficient: (float) ($coefficient ?? $item->percentage_of_oils),
            batchSizeKg: (float) ($production?->planned_quantity ?? 0),
            expectedUnits: $production?->expected_units,
            calculationMode: $item->resolveCalculationMode(),
        );
    }

    private function resolveActiveMergeTarget(ProductionItem $splitItem): ?ProductionItem
    {
        $nextParentId = $splitItem->split_from_item_id;
        $visitedParentIds = [];

        while ($nextParentId !== null && ! in_array($nextParentId, $visitedParentIds, true)) {
            $visitedParentIds[] = $nextParentId;

            $candidateParent = ProductionItem::withTrashed()->find($nextParentId);

            if (! $candidateParent) {
                return null;
            }

            if (! $candidateParent->trashed()) {
                return $candidateParent;
            }

            $nextParentId = $candidateParent->split_from_item_id;
        }

        return null;
    }

    /**
     * Get available supplies for an ingredient, ordered by expiry date (FIFO).
     *
     * @return Collection<int, array{id: int, batch_number: string, available: float, expiry_date: string|null, delivery_date: string|null, supplier_name: string, wave_label: string|null}>
     */
    public function getAvailableSupplies(Ingredient $ingredient): Collection
    {
        return Supply::query()
            ->whereHas('supplierListing', fn ($q) => $q->where('ingredient_id', $ingredient->id))
            ->where('is_in_stock', true)
            ->orderBy('expiry_date')
            ->with([
                'supplierListing.supplier:id,name',
                'supplierOrderItem.supplierOrder.wave:id,name,slug',
                'sourceProduction.wave:id,name,slug',
            ])
            ->get()
            ->filter(fn (Supply $supply) => $supply->getAvailableQuantity() > 0)
            ->map(fn (Supply $supply) => [
                'id' => $supply->id,
                'batch_number' => $supply->batch_number,
                'available' => round($supply->getAvailableQuantity(), 3),
                'expiry_date' => $supply->expiry_date?->format('Y-m-d'),
                'delivery_date' => $supply->delivery_date?->format('Y-m-d'),
                'supplier_name' => $this->resolveSupplySupplierName($supply),
                'wave_label' => $this->resolveSupplyWaveLabel($supply),
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

    private function syncWaveRequirementStatusesForItem(ProductionItem $item): void
    {
        $item->loadMissing('production.wave');

        $wave = $item->production?->wave;

        if (! $wave) {
            return;
        }

        $this->waveRequirementStatusService->syncForWave($wave);
    }

    private function syncWaveRequirementStatusesForProduction(Production $production): void
    {
        $production->loadMissing('wave');

        if (! $production->wave) {
            return;
        }

        $this->waveRequirementStatusService->syncForWave($production->wave);
    }

    private function resolveSupplySupplierName(Supply $supply): string
    {
        if ($supply->source_production_id !== null) {
            return Settings::internalSupplierLabel();
        }

        return (string) ($supply->supplierListing?->supplier?->name ?: __('N/A'));
    }

    private function resolveSupplyWaveLabel(Supply $supply): ?string
    {
        $orderWave = $supply->supplierOrderItem?->supplierOrder?->wave;

        if ($orderWave) {
            return sprintf('%s (%s)', $orderWave->name, $orderWave->slug);
        }

        $sourceWave = $supply->sourceProduction?->wave;

        if ($sourceWave) {
            return sprintf('%s (%s)', $sourceWave->name, $sourceWave->slug);
        }

        return null;
    }
}
