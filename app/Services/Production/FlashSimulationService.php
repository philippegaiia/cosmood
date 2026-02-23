<?php

namespace App\Services\Production;

use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Product;

class FlashSimulationService
{
    public function simulate(array $lines): array
    {
        $normalizedLines = collect($lines)
            ->map(fn (array $line): array => [
                'product_id' => isset($line['product_id']) ? (int) $line['product_id'] : null,
                'units' => isset($line['units']) ? (float) $line['units'] : 0,
            ])
            ->filter(fn (array $line): bool => $line['product_id'] !== null && $line['units'] > 0)
            ->values();

        if ($normalizedLines->isEmpty()) {
            return [
                'product_lines' => collect(),
                'ingredient_totals' => collect(),
                'warnings' => collect(),
                'totals' => [
                    'products_count' => 0,
                    'total_units' => 0,
                    'total_batch_kg' => 0,
                    'total_estimated_cost' => 0,
                ],
            ];
        }

        $products = Product::query()
            ->with([
                'productType:id,name,slug',
                'productCategory:id,name',
                'formulas' => fn ($query) => $query
                    ->where('is_active', true)
                    ->with(['formulaItems.ingredient'])
                    ->orderByDesc('id'),
            ])
            ->whereIn('id', $normalizedLines->pluck('product_id')->all())
            ->get()
            ->keyBy('id');

        $productLines = collect();
        $ingredientTotals = collect();
        $warnings = collect();

        foreach ($normalizedLines as $line) {
            /** @var Product|null $product */
            $product = $products->get($line['product_id']);

            if (! $product) {
                $warnings->push('Produit #'.$line['product_id'].' introuvable.');

                continue;
            }

            $formula = $this->resolveFormula($product);

            if (! $formula) {
                $warnings->push('Aucune formule active pour '.$product->name.'.');

                continue;
            }

            $multiplier = $this->getProductMultiplier($product);
            $oilsWeightKg = round(((float) $line['units'] * (float) $product->net_weight / 1000) * $multiplier, 3);

            if ($oilsWeightKg <= 0) {
                continue;
            }

            $lineEstimatedCost = 0.0;
            $formula->loadMissing('formulaItems.ingredient');

            /** @var FormulaItem $formulaItem */
            foreach ($formula->formulaItems as $formulaItem) {
                $ingredient = $formulaItem->ingredient;

                if (! $ingredient) {
                    continue;
                }

                $requiredKg = round(((float) $formulaItem->percentage_of_oils / 100) * $oilsWeightKg, 3);
                $unitPrice = (float) ($ingredient->price ?? 0);
                $estimatedCost = round($requiredKg * $unitPrice, 2);

                $lineEstimatedCost += $estimatedCost;

                if (! $ingredientTotals->has($ingredient->id)) {
                    $ingredientTotals->put($ingredient->id, [
                        'ingredient_id' => $ingredient->id,
                        'ingredient_name' => $ingredient->name,
                        'required_kg' => 0.0,
                        'unit_price' => $unitPrice,
                        'estimated_cost' => 0.0,
                    ]);
                }

                $current = $ingredientTotals->get($ingredient->id);
                $current['required_kg'] = round((float) $current['required_kg'] + $requiredKg, 3);
                $current['estimated_cost'] = round((float) $current['estimated_cost'] + $estimatedCost, 2);

                if ((float) $current['unit_price'] <= 0 && $unitPrice > 0) {
                    $current['unit_price'] = $unitPrice;
                }

                $ingredientTotals->put($ingredient->id, $current);
            }

            $productLines->push([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'formula_name' => $formula->name,
                'units' => (float) $line['units'],
                'net_weight_g' => (float) $product->net_weight,
                'multiplier' => $multiplier,
                'oils_kg' => $oilsWeightKg,
                'estimated_cost' => round($lineEstimatedCost, 2),
            ]);
        }

        $ingredientTotals = $ingredientTotals
            ->values()
            ->sortByDesc('required_kg')
            ->values();

        return [
            'product_lines' => $productLines,
            'ingredient_totals' => $ingredientTotals,
            'warnings' => $warnings,
            'totals' => [
                'products_count' => $productLines->count(),
                'total_units' => (float) $productLines->sum('units'),
                'total_batch_kg' => round((float) $productLines->sum('oils_kg'), 3),
                'total_estimated_cost' => round((float) $ingredientTotals->sum('estimated_cost'), 2),
            ],
        ];
    }

    private function resolveFormula(Product $product): ?Formula
    {
        $activeFormula = $product->formulas
            ->first(fn (Formula $formula): bool => $formula->formulaItems->isNotEmpty());

        if ($activeFormula) {
            return $activeFormula;
        }

        return $product->formulas()
            ->with('formulaItems.ingredient')
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get()
            ->first(fn (Formula $formula): bool => $formula->formulaItems->isNotEmpty());
    }

    private function getProductMultiplier(Product $product): float
    {
        $productName = strtolower((string) $product->name);
        $typeSlug = strtolower((string) ($product->productType?->slug ?? ''));
        $typeName = strtolower((string) ($product->productType?->name ?? ''));
        $categoryName = strtolower((string) ($product->productCategory?->name ?? ''));

        $isSoap =
            $typeSlug === 'soap-bars'
            || str_contains($typeName, 'soap')
            || str_contains($typeName, 'savon')
            || str_contains($categoryName, 'soap')
            || str_contains($categoryName, 'savon')
            || str_contains($productName, 'soap')
            || str_contains($productName, 'savon');

        if ($isSoap) {
            return 1.15;
        }

        return 1.0;
    }
}
