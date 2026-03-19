<?php

namespace App\Services\Production;

use App\Enums\AllocationStatus;
use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Production\ProductionWave;
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
        private readonly WaveProcurementService $waveProcurementService,
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
                    $this->syncPrimarySupplyTraceability($item);

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
            $this->syncPrimarySupplyTraceability($item);

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
            $this->syncPrimarySupplyTraceability($item);
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
            $this->syncPrimarySupplyTraceability($item);
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

            $item->updateAllocationStatus();
            $this->syncPrimarySupplyTraceability($item);
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
            ->when(! $includePackaging, fn ($q) => $q->where('phase', '!=', Phases::Packaging->value))
            ->when(! $includeNonPackaging, fn ($q) => $q->where('phase', Phases::Packaging->value))
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
                'allocation_status' => AllocationStatus::Unassigned,
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
                return $lockedSplitItem;
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

            $lockedSplitItem->delete();

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

    /**
     * Resolve the active merge target for a split item.
     *
     * With transactional hard deletes, deleted split parents are no longer available through
     * soft-delete lookups. Children therefore merge back to the first live parent if it still
     * exists, otherwise they fall back to the live split root tracked on the item itself.
     */
    private function resolveActiveMergeTarget(ProductionItem $splitItem): ?ProductionItem
    {
        if ($splitItem->split_from_item_id !== null) {
            $directParent = ProductionItem::query()->find($splitItem->split_from_item_id);

            if ($directParent) {
                return $directParent;
            }
        }

        if ($splitItem->split_root_item_id !== null && $splitItem->split_root_item_id !== $splitItem->id) {
            return ProductionItem::query()->find($splitItem->split_root_item_id);
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

    /**
     * Allocate real stock to a wave ingredient across productions by production date order.
     *
     * This is an operational stock action. It uses actual lots already in stock and keeps
     * traceability through normal allocation records instead of relying on planning-only values.
     * Allocation is intentionally strict at wave level: an item is allocated only if its full
     * remaining quantity can be covered now. Partial automatic allocations would force operators
     * to reason about implicit shortages without a split workflow.
     *
     * @return array{requested_quantity: float, allocated_quantity: float, remaining_quantity: float, items_touched: int, allocations_created: int, supplies_used: int}
     */
    public function allocateWaveIngredientStock(ProductionWave $wave, Ingredient $ingredient, ?float $quantity = null): array
    {
        $items = $this->getWaveIngredientItems($wave, $ingredient);

        return $this->allocateIngredientItemsStrict($items, $ingredient, $quantity);
    }

    /**
     * Allocate real stock to a single orphan production ingredient.
     *
     * This mirrors the wave strict-allocation behavior, but limits the scope to
     * one production so planners can handle autonomous batches directly from the
     * production list without using the execution sheet.
     *
     * @return array{requested_quantity: float, allocated_quantity: float, remaining_quantity: float, items_touched: int, allocations_created: int, supplies_used: int}
     */
    public function allocateProductionIngredientStock(Production $production, Ingredient $ingredient, ?float $quantity = null): array
    {
        $items = $this->getProductionIngredientItems($production, $ingredient);
        $linkedOpenOrderQuantity = (float) ($this->waveProcurementService
            ->getOpenLinkedOrderQuantitiesForProduction($production)
            ->get($ingredient->id) ?? 0);

        return $this->allocateIngredientItemsStrict($items, $ingredient, $quantity, $linkedOpenOrderQuantity);
    }

    /**
     * Allocate a specific received lot to a wave, keeping lot-level traceability.
     *
     * This is used right after reception when operations want to reserve the exact
     * newly received lot for the linked wave instead of letting the generic stock
     * allocator pick any other compatible lot first.
     *
     * @return array{requested_quantity: float, allocated_quantity: float, remaining_quantity: float, items_touched: int, allocations_created: int}
     */
    public function allocateSupplyToWave(Supply $supply, ProductionWave $wave, ?float $quantity = null): array
    {
        $ingredient = $this->resolveIngredientForSupply($supply);
        $items = $this->getWaveIngredientItems($wave, $ingredient);

        return $this->allocateItemsStrictFromSingleSupply($items, $supply, $quantity);
    }

    /**
     * Allocate a specific received lot to one orphan production.
     *
     * @return array{requested_quantity: float, allocated_quantity: float, remaining_quantity: float, items_touched: int, allocations_created: int}
     */
    public function allocateSupplyToProduction(Supply $supply, Production $production, ?float $quantity = null): array
    {
        $ingredient = $this->resolveIngredientForSupply($supply);
        $items = $this->getProductionIngredientItems($production, $ingredient);
        $linkedOpenOrderQuantity = (float) ($this->waveProcurementService
            ->getOpenLinkedOrderQuantitiesForProduction($production)
            ->get($ingredient->id) ?? 0);

        return $this->allocateItemsStrictFromSingleSupply($items, $supply, $quantity, $linkedOpenOrderQuantity);
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

    /**
     * Keep the production item lot pointer aligned with active allocations.
     *
     * Allocation history remains the source of truth. `production_items.supply_id`
     * and `production_items.supply_batch_number` are convenience fields used by
     * tables, finish guards, and traceability displays.
     */
    private function syncPrimarySupplyTraceability(ProductionItem $item): void
    {
        $primaryAllocation = $item->allocations()
            ->with('supply:id,batch_number')
            ->whereIn('status', ['reserved', 'consumed'])
            ->orderByRaw("CASE WHEN status = 'reserved' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        $item->forceFill([
            'supply_id' => $primaryAllocation?->supply_id,
            'supply_batch_number' => $primaryAllocation?->supply?->batch_number,
            'is_supplied' => $primaryAllocation !== null,
        ])->saveQuietly();
    }

    /**
     * @return Collection<int, Supply>
     */
    private function getAllocatableSuppliesForIngredient(Ingredient $ingredient): Collection
    {
        return Supply::query()
            ->whereHas('supplierListing', fn ($query) => $query->where('ingredient_id', $ingredient->id))
            ->where('is_in_stock', true)
            ->with('supplierListing:id,ingredient_id')
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('delivery_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Supply $supply): bool => $supply->getAvailableQuantity() > 0)
            ->values();
    }

    /**
     * @param  Collection<int, ProductionItem>  $items
     * @return array{requested_quantity: float, allocated_quantity: float, remaining_quantity: float, items_touched: int, allocations_created: int, supplies_used: int}
     */
    private function allocateIngredientItemsStrict(Collection $items, Ingredient $ingredient, ?float $quantity = null, float $externallyCoveredQuantity = 0): array
    {
        $supplies = $this->getAllocatableSuppliesForIngredient($ingredient);
        $effectiveDemands = $this->getEffectiveItemDemands($items, $externallyCoveredQuantity);

        $requestedQuantity = round((float) ($quantity ?? array_sum($effectiveDemands)), 3);

        if ($requestedQuantity <= 0) {
            throw new \InvalidArgumentException(__('La quantité à allouer doit être supérieure à zéro.'));
        }

        if ($ingredient->base_unit?->value === 'u' && abs($requestedQuantity - round($requestedQuantity)) > 0.0001) {
            throw new \InvalidArgumentException(__('La quantité à allouer doit être entière pour les ingrédients unitaires.'));
        }

        $remainingQuantity = $requestedQuantity;
        $allocatedQuantity = 0.0;
        $allocationsCreated = 0;
        $touchedItemIds = [];
        $usedSupplyIds = [];

        foreach ($items as $item) {
            if ($remainingQuantity <= 0) {
                break;
            }

            $itemRequiredQuantity = (float) ($effectiveDemands[$item->id] ?? 0);
            $itemRemaining = $itemRequiredQuantity;

            if ($itemRequiredQuantity <= 0) {
                continue;
            }

            if ($remainingQuantity < $itemRequiredQuantity) {
                continue;
            }

            $totalAvailableAcrossSupplies = round((float) $supplies->sum(fn (Supply $supply): float => $supply->getAvailableQuantity()), 3);

            if ($totalAvailableAcrossSupplies < $itemRequiredQuantity) {
                continue;
            }

            foreach ($supplies as $supply) {
                if ($itemRemaining <= 0 || $remainingQuantity <= 0) {
                    break;
                }

                $available = round($supply->getAvailableQuantity(), 3);

                if ($available <= 0) {
                    continue;
                }

                $allocationQuantity = round(min($itemRemaining, $available, $remainingQuantity), 3);

                if ($allocationQuantity <= 0) {
                    continue;
                }

                $this->allocate($item, $supply, $allocationQuantity);

                $allocatedQuantity += $allocationQuantity;
                $remainingQuantity = round(max(0, $remainingQuantity - $allocationQuantity), 3);
                $itemRemaining = round(max(0, $itemRemaining - $allocationQuantity), 3);
                $allocationsCreated++;
                $touchedItemIds[$item->id] = true;
                $usedSupplyIds[$supply->id] = true;
            }

            if ($itemRemaining > 0) {
                throw new \RuntimeException('Strict allocation left an item partially allocated.');
            }
        }

        return [
            'requested_quantity' => round($requestedQuantity, 3),
            'allocated_quantity' => round($allocatedQuantity, 3),
            'remaining_quantity' => round(max(0, $requestedQuantity - $allocatedQuantity), 3),
            'items_touched' => count($touchedItemIds),
            'allocations_created' => $allocationsCreated,
            'supplies_used' => count($usedSupplyIds),
        ];
    }

    /**
     * Allocate only from the provided lot, never from other compatible supplies.
     *
     * @param  Collection<int, ProductionItem>  $items
     * @return array{requested_quantity: float, allocated_quantity: float, remaining_quantity: float, items_touched: int, allocations_created: int}
     */
    private function allocateItemsStrictFromSingleSupply(Collection $items, Supply $supply, ?float $quantity = null, float $externallyCoveredQuantity = 0): array
    {
        $ingredient = $this->resolveIngredientForSupply($supply);
        $availableOnSupply = round($supply->getAvailableQuantity(), 3);
        $effectiveDemands = $this->getEffectiveItemDemands($items, $externallyCoveredQuantity);
        $maxDemand = round((float) array_sum($effectiveDemands), 3);
        $requestedQuantity = round((float) ($quantity ?? min($availableOnSupply, $maxDemand)), 3);

        if ($requestedQuantity <= 0) {
            throw new \InvalidArgumentException(__('La quantité à allouer doit être supérieure à zéro.'));
        }

        if ($ingredient->base_unit?->value === 'u' && abs($requestedQuantity - round($requestedQuantity)) > 0.0001) {
            throw new \InvalidArgumentException(__('La quantité à allouer doit être entière pour les ingrédients unitaires.'));
        }

        $remainingQuantity = min($requestedQuantity, $availableOnSupply);
        $allocatedQuantity = 0.0;
        $allocationsCreated = 0;
        $touchedItemIds = [];

        foreach ($items as $item) {
            if ($remainingQuantity <= 0) {
                break;
            }

            $itemRequiredQuantity = (float) ($effectiveDemands[$item->id] ?? 0);

            if ($itemRequiredQuantity <= 0 || $remainingQuantity < $itemRequiredQuantity) {
                continue;
            }

            $currentSupply = $supply->fresh();
            $currentAvailable = round($currentSupply?->getAvailableQuantity() ?? 0, 3);

            if ($currentAvailable < $itemRequiredQuantity) {
                continue;
            }

            $this->allocate($item, $currentSupply, $itemRequiredQuantity);

            $allocatedQuantity += $itemRequiredQuantity;
            $remainingQuantity = round(max(0, $remainingQuantity - $itemRequiredQuantity), 3);
            $allocationsCreated++;
            $touchedItemIds[$item->id] = true;
        }

        return [
            'requested_quantity' => round($requestedQuantity, 3),
            'allocated_quantity' => round($allocatedQuantity, 3),
            'remaining_quantity' => round(max(0, $requestedQuantity - $allocatedQuantity), 3),
            'items_touched' => count($touchedItemIds),
            'allocations_created' => $allocationsCreated,
        ];
    }

    /**
     * @param  Collection<int, ProductionItem>  $items
     * @return array<int, float>
     */
    private function getEffectiveItemDemands(Collection $items, float $externallyCoveredQuantity): array
    {
        $remainingExternalCoverage = round(max(0, $externallyCoveredQuantity), 3);
        $effectiveDemands = [];

        foreach ($items as $item) {
            $unallocatedQuantity = round($item->getUnallocatedQuantity(), 3);

            if ($unallocatedQuantity <= 0) {
                $effectiveDemands[$item->id] = 0.0;

                continue;
            }

            $coveredByLinkedOrders = min($unallocatedQuantity, $remainingExternalCoverage);
            $remainingExternalCoverage = round(max(0, $remainingExternalCoverage - $coveredByLinkedOrders), 3);
            $effectiveDemands[$item->id] = round(max(0, $unallocatedQuantity - $coveredByLinkedOrders), 3);
        }

        return $effectiveDemands;
    }

    /**
     * @return Collection<int, ProductionItem>
     */
    private function getWaveIngredientItems(ProductionWave $wave, Ingredient $ingredient): Collection
    {
        $wave->loadMissing([
            'productions.productionItems.allocations',
            'productions.productionItems.ingredient',
            'productions.productionItems.production',
            'productions.masterbatchLot',
        ]);

        $items = collect();

        foreach ($wave->productions->filter(fn (Production $production): bool => $production->status !== ProductionStatus::Cancelled) as $production) {
            $replacedPhase = $production->masterbatch_lot_id
                ? $this->normalizeReplacedPhase($production->masterbatchLot?->replaces_phase)
                : null;

            $productionItems = $production->productionItems
                ->when($replacedPhase !== null, fn ($collection) => $collection->where('phase', '!=', $replacedPhase))
                ->where('ingredient_id', $ingredient->id)
                ->filter(fn (ProductionItem $item): bool => $item->getUnallocatedQuantity() > 0);

            $items = $items->merge($productionItems);
        }

        return $items
            ->sortBy(fn (ProductionItem $item): string => ($item->production?->production_date?->format('Y-m-d') ?? '9999-12-31').'-'.str_pad((string) $item->id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * @return Collection<int, ProductionItem>
     */
    private function getProductionIngredientItems(Production $production, Ingredient $ingredient): Collection
    {
        $production = $production->fresh(['masterbatchLot']) ?? $production;

        if ($production->status === ProductionStatus::Cancelled) {
            return collect();
        }

        $replacedPhase = $production->masterbatch_lot_id
            ? $this->normalizeReplacedPhase($production->masterbatchLot?->replaces_phase)
            : null;

        return ProductionItem::query()
            ->where('production_id', $production->id)
            ->where('ingredient_id', $ingredient->id)
            ->with(['allocations', 'ingredient'])
            ->when($replacedPhase !== null, fn ($query) => $query->where('phase', '!=', $replacedPhase))
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->each(fn (ProductionItem $item): ProductionItem => $item->setRelation('production', $production))
            ->filter(fn (ProductionItem $item): bool => $item->getUnallocatedQuantity() > 0)
            ->values();
    }

    private function normalizeReplacedPhase(?string $phase): ?string
    {
        return Phases::normalize($phase);
    }

    private function resolveIngredientForSupply(Supply $supply): Ingredient
    {
        $supply->loadMissing('supplierListing.ingredient');

        $ingredient = $supply->supplierListing?->ingredient;

        if (! $ingredient) {
            throw new \InvalidArgumentException(__('Impossible de déterminer l’ingrédient de ce lot.'));
        }

        return $ingredient;
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
