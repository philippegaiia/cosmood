<?php

namespace App\Models\Production;

use App\Enums\FormulaItemCalculationMode;
use App\Enums\IngredientBaseUnit;
use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\Supply;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class ProductionItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'organic' => 'boolean',
        'is_supplied' => 'boolean',
        'calculation_mode' => FormulaItemCalculationMode::class,
    ];

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

    public function getPhaseLabel(): string
    {
        return Phases::tryFrom((string) $this->phase)?->getLabel() ?? (string) $this->phase;
    }

    public function getCalculatedQuantityKg(?Production $production = null): float
    {
        $production ??= $this->relationLoaded('production')
            ? $this->production
            : ($this->production_id
                ? Production::query()->select(['id', 'planned_quantity', 'expected_units'])->find($this->production_id)
                : null);

        $coefficient = (float) ($this->percentage_of_oils ?? 0);
        $calculationMode = $this->resolveCalculationMode();

        if ($calculationMode === FormulaItemCalculationMode::QuantityPerUnit) {
            $expectedUnits = (float) ($production?->expected_units ?? 0);

            return round($expectedUnits * $coefficient, 3);
        }

        $plannedQuantity = (float) ($production?->planned_quantity ?? 0);

        return round(($plannedQuantity * $coefficient) / 100, 3);
    }

    public function isPackagingPhase(): bool
    {
        return (string) $this->phase === Phases::Packaging->value;
    }

    public function resolveCalculationMode(): FormulaItemCalculationMode
    {
        if ($this->isPackagingPhase()) {
            return FormulaItemCalculationMode::QuantityPerUnit;
        }

        $ingredient = $this->relationLoaded('ingredient')
            ? $this->ingredient
            : ($this->ingredient_id
                ? Ingredient::query()->select(['id', 'base_unit'])->find($this->ingredient_id)
                : null);

        $ingredientBaseUnit = $ingredient?->base_unit;
        $isUnitBasedIngredient = $ingredientBaseUnit instanceof IngredientBaseUnit
            ? $ingredientBaseUnit === IngredientBaseUnit::Unit
            : (string) $ingredientBaseUnit === IngredientBaseUnit::Unit->value;

        if ($isUnitBasedIngredient) {
            return FormulaItemCalculationMode::QuantityPerUnit;
        }

        if ($this->calculation_mode instanceof FormulaItemCalculationMode) {
            return $this->calculation_mode;
        }

        $mode = FormulaItemCalculationMode::tryFrom((string) ($this->calculation_mode ?? ''));

        if ($mode) {
            return $mode;
        }

        return FormulaItemCalculationMode::PercentOfOils;
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
