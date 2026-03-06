<?php

namespace App\Models\Production;

use App\Enums\AllocationStatus;
use App\Enums\FormulaItemCalculationMode;
use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\Supply;
use App\Services\Production\IngredientQuantityCalculator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class ProductionItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'organic' => 'boolean',
            'is_supplied' => 'boolean',
            'is_order_marked' => 'boolean',
            'required_quantity' => 'decimal:3',
            'calculation_mode' => FormulaItemCalculationMode::class,
            'procurement_status' => ProcurementStatus::class,
            'allocation_status' => AllocationStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductionItem $item): void {
            if ($item->supply_id !== null) {
                $item->is_supplied = true;
            }
        });

        static::deleting(function (ProductionItem $item): void {
            $production = $item->relationLoaded('production')
                ? $item->production
                : $item->production()->first();

            if ($production?->status === ProductionStatus::Finished) {
                throw new InvalidArgumentException('Production items cannot be deleted once production is finished.');
            }

            if (! $item->isForceDeleting()) {
                throw new InvalidArgumentException('Production items must be permanently deleted.');
            }

            $hasActiveAllocations = $item->allocations()
                ->whereIn('status', ['reserved', 'consumed'])
                ->exists();

            if ($hasActiveAllocations) {
                throw new InvalidArgumentException('Production items with active allocations cannot be deleted.');
            }
        });
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function production_task(): BelongsTo
    {
        return $this->supplierListing();
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function supplierListing(): BelongsTo
    {
        return $this->belongsTo(SupplierListing::class);
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ProductionItemAllocation::class);
    }

    /**
     * Get the parent item if this was split.
     */
    public function splitParent(): BelongsTo
    {
        return $this->belongsTo(ProductionItem::class, 'split_from_item_id');
    }

    /**
     * Get the root item if this was split.
     */
    public function splitRoot(): BelongsTo
    {
        return $this->belongsTo(ProductionItem::class, 'split_root_item_id');
    }

    /**
     * Get child items if this was split.
     */
    public function splitChildren(): HasMany
    {
        return $this->hasMany(ProductionItem::class, 'split_from_item_id');
    }

    /**
     * Get sibling items from the same split.
     */
    public function splitSiblings(): HasMany
    {
        return $this->hasMany(ProductionItem::class, 'split_root_item_id')
            ->where('id', '!=', $this->id);
    }

    /**
     * Check if this item was split from another item.
     */
    public function isSplitChild(): bool
    {
        return $this->split_from_item_id !== null;
    }

    /**
     * Get all items in the split chain.
     *
     * @return \Illuminate\Support\Collection<int, ProductionItem>
     */
    public function getSplitChain(): \Illuminate\Support\Collection
    {
        if ($this->split_root_item_id === null) {
            return collect([$this])->merge($this->splitChildren);
        }

        $root = $this->splitRoot;

        return collect([$root])
            ->merge($root->splitChildren)
            ->sortBy('sort')
            ->values();
    }

    /**
     * Returns the total quantity allocated from all supplies.
     */
    public function getTotalAllocatedQuantity(): float
    {
        return (float) $this->allocations()
            ->whereIn('status', ['reserved', 'consumed'])
            ->sum('quantity');
    }

    /**
     * Returns the quantity still needing allocation.
     */
    public function getUnallocatedQuantity(): float
    {
        $required = $this->required_quantity > 0
            ? $this->required_quantity
            : $this->getCalculatedQuantityKg();

        return max(0, $required - $this->getTotalAllocatedQuantity());
    }

    /**
     * Checks if the item is fully allocated.
     */
    public function isFullyAllocated(): bool
    {
        return round($this->getUnallocatedQuantity(), 3) <= 0;
    }

    public function isCoveredByProcurementSignal(): bool
    {
        if ($this->isFullyAllocated()) {
            return true;
        }

        if ($this->is_order_marked) {
            return true;
        }

        return in_array($this->procurement_status, [
            ProcurementStatus::Ordered,
            ProcurementStatus::Confirmed,
            ProcurementStatus::Received,
        ], true);
    }

    /**
     * Updates the allocation_status based on current allocations.
     */
    public function updateAllocationStatus(): void
    {
        $allocatedQuantity = $this->getTotalAllocatedQuantity();
        $requiredQuantity = $this->required_quantity > 0
            ? $this->required_quantity
            : $this->getCalculatedQuantityKg();

        if ($allocatedQuantity <= 0) {
            $this->allocation_status = AllocationStatus::Unassigned;
        } elseif (round($allocatedQuantity, 3) >= round($requiredQuantity, 3)) {
            $this->allocation_status = AllocationStatus::Allocated;
        } else {
            $this->allocation_status = AllocationStatus::Partial;
        }

        $this->saveQuietly();
    }

    public function getPhaseLabel(): string
    {
        return Phases::tryFrom((string) $this->phase)?->getLabel() ?? (string) $this->phase;
    }

    /**
     * Calculates the required quantity for this item based on its coefficient and calculation mode.
     *
     * Delegates to IngredientQuantityCalculator. The coefficient (percentage_of_oils) is interpreted
     * as either a percentage of batch weight or quantity per unit, depending on the resolved mode.
     *
     * @see IngredientQuantityCalculator::calculate()
     */
    public function getCalculatedQuantityKg(?Production $production = null): float
    {
        $production ??= $this->relationLoaded('production')
            ? $this->production
            : ($this->production_id
                ? Production::query()->select(['id', 'planned_quantity', 'expected_units'])->find($this->production_id)
                : null);

        $coefficient = (float) ($this->percentage_of_oils ?? 0);
        $calculationMode = $this->resolveCalculationMode();

        $calculator = app(IngredientQuantityCalculator::class);

        return $calculator->calculate(
            coefficient: $coefficient,
            batchSizeKg: (float) ($production?->planned_quantity ?? 0),
            expectedUnits: $production?->expected_units,
            calculationMode: $calculationMode,
        );
    }

    public function isPackagingPhase(): bool
    {
        return (string) $this->phase === Phases::Packaging->value;
    }

    /**
     * Resolves the calculation mode for this item.
     *
     * Priority: ingredient base_unit (unit) > explicit calculation_mode > default (percent_of_oils).
     * Phase is NOT considered - mode is determined solely by ingredient type or explicit setting.
     *
     * @see IngredientQuantityCalculator::resolveCalculationMode()
     */
    public function resolveCalculationMode(): FormulaItemCalculationMode
    {
        $ingredient = $this->relationLoaded('ingredient')
            ? $this->ingredient
            : ($this->ingredient_id
                ? Ingredient::query()->select(['id', 'base_unit'])->find($this->ingredient_id)
                : null);

        $ingredientBaseUnit = $ingredient?->base_unit;

        $calculator = app(IngredientQuantityCalculator::class);

        return $calculator->resolveCalculationMode(
            ingredientBaseUnit: $ingredientBaseUnit,
            storedMode: $this->calculation_mode,
        );
    }

    public function getReferenceUnitPrice(): ?float
    {
        $supply = $this->relationLoaded('supply')
            ? $this->supply
            : ($this->supply_id
                ? Supply::query()->select(['id', 'unit_price'])->find($this->supply_id)
                : null);

        $supplyUnitPrice = $supply?->unit_price;

        if ($supplyUnitPrice !== null) {
            return (float) $supplyUnitPrice;
        }

        $supplierListing = $this->relationLoaded('supplierListing')
            ? $this->supplierListing
            : ($this->supplier_listing_id
                ? SupplierListing::query()->select(['id', 'price'])->find($this->supplier_listing_id)
                : null);

        $listingPrice = $supplierListing?->price;

        if ($listingPrice !== null) {
            return (float) $listingPrice;
        }

        $ingredient = $this->relationLoaded('ingredient')
            ? $this->ingredient
            : ($this->ingredient_id
                ? Ingredient::query()->select(['id', 'price'])->find($this->ingredient_id)
                : null);

        $ingredientPrice = $ingredient?->price;

        if ($ingredientPrice !== null) {
            return (float) $ingredientPrice;
        }

        return null;
    }

    public function getEstimatedCost(): ?float
    {
        $unitPrice = $this->getReferenceUnitPrice();

        if ($unitPrice === null) {
            return null;
        }

        return round($this->getCalculatedQuantityKg() * $unitPrice, 2);
    }
}
