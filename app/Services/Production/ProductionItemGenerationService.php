<?php

namespace App\Services\Production;

use App\Enums\FormulaItemCalculationMode;
use App\Enums\Phases;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use Illuminate\Support\Facades\DB;

/**
 * Creates production items from formula items and product packaging when a production is created.
 */
class ProductionItemGenerationService
{
    /**
     * Generates production items once per production.
     * Includes formula ingredients and product packaging.
     */
    public function generateFromFormula(Production $production): void
    {
        if ($production->productionItems()->exists()) {
            return;
        }

        $formula = $production->formula;
        $product = $production->product;

        if (! $formula) {
            return;
        }

        DB::transaction(function () use ($production, $formula, $product): void {
            // Generate items from formula ingredients (phases 10/20/30)
            foreach ($formula->formulaItems as $formulaItem) {
                ProductionItem::query()->create([
                    'production_id' => $production->id,
                    'ingredient_id' => $formulaItem->ingredient_id,
                    'supplier_listing_id' => null,
                    'supply_id' => null,
                    'supply_batch_number' => null,
                    'percentage_of_oils' => $formulaItem->percentage_of_oils,
                    'phase' => $formulaItem->phase->value,
                    'calculation_mode' => $formulaItem->calculation_mode?->value ?? $formulaItem->calculation_mode,
                    'organic' => $formulaItem->organic,
                    'is_supplied' => false,
                    'sort' => $formulaItem->sort,
                ]);
            }

            // Generate items from product packaging (if product exists)
            if ($product) {
                $this->generatePackagingItems($production, $product);
            }
        });
    }

    /**
     * Generate production items from product packaging.
     */
    private function generatePackagingItems(Production $production, $product): void
    {
        $packagingItems = $product->packaging()->get();

        foreach ($packagingItems as $packaging) {
            ProductionItem::query()->create([
                'production_id' => $production->id,
                'ingredient_id' => $packaging->id,
                'supplier_listing_id' => null,
                'supply_id' => null,
                'supply_batch_number' => null,
                'percentage_of_oils' => $packaging->pivot->quantity_per_unit,
                'phase' => Phases::Packaging->value,
                'calculation_mode' => FormulaItemCalculationMode::QuantityPerUnit->value,
                'organic' => false,
                'is_supplied' => false,
                'sort' => $packaging->pivot->sort,
            ]);
        }
    }
}
