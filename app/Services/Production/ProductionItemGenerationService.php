<?php

namespace App\Services\Production;

use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use Illuminate\Support\Facades\DB;

/**
 * Creates production items from formula items when a production is created.
 */
class ProductionItemGenerationService
{
    /**
     * Generates production items once per production.
     */
    public function generateFromFormula(Production $production): void
    {
        if ($production->productionItems()->exists()) {
            return;
        }

        $formula = $production->formula;

        if (! $formula) {
            return;
        }

        DB::transaction(function () use ($production, $formula): void {
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
        });
    }
}
