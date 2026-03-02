<?php

namespace App\Services\Production;

use App\Enums\IngredientBaseUnit;
use App\Models\Production\BatchSizePreset;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\TaskTemplate;

/**
 * Computes a non-persistent procurement and cost estimate from product/unit inputs.
 */
class FlashSimulationService
{
    public function __construct(
        private readonly IngredientQuantityCalculator $calculator,
    ) {}

    /**
     * @param  array<int, array{product_id: int|string|null, desired_units?: int|float|string|null, units?: int|float|string|null, batch_size_preset_id?: int|string|null}>  $lines
     * @return array{
     *     product_lines: \Illuminate\Support\Collection<int, array{product_id: int, product_name: string, formula_name: string, desired_units: float, units: float, units_per_batch: float, batches_required: int, produced_units: float, extra_units: float, batch_size_kg: float, oils_kg: float, estimated_cost: float, batch_preset_name: string|null, duration_per_batch_minutes: int, total_duration_minutes: int}>,
     *     ingredient_totals: \Illuminate\Support\Collection<int, array{ingredient_id: int, ingredient_name: string, required_quantity: float, base_unit: string, unit_price: float, estimated_cost: float}>,
     *     warnings: \Illuminate\Support\Collection<int, string>,
     *     totals: array{products_count: int, total_units: float|int, total_desired_units: float|int, total_produced_units: float|int, total_extra_units: float|int, total_batches: int, total_batch_kg: float|int, total_estimated_cost: float|int, total_duration_minutes: int}
     * }
     */
    public function simulate(array $lines): array
    {
        $normalizedLines = collect($lines)
            ->map(fn (array $line): array => [
                'product_id' => isset($line['product_id']) ? (int) $line['product_id'] : null,
                'desired_units' => isset($line['desired_units'])
                    ? (float) $line['desired_units']
                    : (isset($line['units']) ? (float) $line['units'] : 0),
                'batch_size_preset_id' => isset($line['batch_size_preset_id']) && $line['batch_size_preset_id'] !== ''
                    ? (int) $line['batch_size_preset_id']
                    : null,
            ])
            ->filter(fn (array $line): bool => $line['product_id'] !== null && $line['desired_units'] > 0)
            ->values();

        if ($normalizedLines->isEmpty()) {
            return $this->emptyResult();
        }

        $products = Product::query()
            ->with([
                'productType:id,name,slug,default_batch_size,expected_units_output',
                'productType.batchSizePresets',
                'productType.taskTemplates.items',
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

            $batchConfiguration = $this->resolveBatchConfiguration($product, $line['batch_size_preset_id']);
            $unitsPerBatch = $batchConfiguration['units_per_batch'];
            $batchSizeKg = $batchConfiguration['batch_size_kg'];

            if ($unitsPerBatch <= 0 || $batchSizeKg <= 0) {
                $warnings->push('Config batch manquante pour '.$product->name.' (type produit incomplet).');

                continue;
            }

            $batchesRequired = (int) ceil((float) $line['desired_units'] / $unitsPerBatch);

            if ($batchesRequired <= 0) {
                continue;
            }

            $producedUnits = (float) ($batchesRequired * $unitsPerBatch);
            $extraUnits = max(0, $producedUnits - (float) $line['desired_units']);
            $oilsWeightKg = round($batchesRequired * $batchSizeKg, 3);

            $durationPerBatch = $this->resolveDurationPerBatch($product->productType?->defaultTaskTemplate());
            $totalDuration = $durationPerBatch * $batchesRequired;

            $lineEstimatedCost = 0.0;
            $formula->loadMissing('formulaItems.ingredient');

            foreach ($formula->formulaItems as $formulaItem) {
                $ingredient = $formulaItem->ingredient;

                if (! $ingredient) {
                    continue;
                }

                $requiredQuantity = $this->calculator->resolveAndCalculate(
                    coefficient: (float) ($formulaItem->percentage_of_oils ?? 0),
                    batchSizeKg: $oilsWeightKg,
                    expectedUnits: $producedUnits,
                    ingredientBaseUnit: $ingredient->base_unit?->value ?? $ingredient->base_unit,
                    storedMode: $formulaItem->calculation_mode?->value ?? $formulaItem->calculation_mode,
                );

                $baseUnit = $ingredient->base_unit instanceof IngredientBaseUnit
                    ? $ingredient->base_unit->value
                    : (string) ($ingredient->base_unit ?? 'kg');

                $unitPrice = (float) ($ingredient->price ?? 0);
                $estimatedCost = round($requiredQuantity * $unitPrice, 2);

                $lineEstimatedCost += $estimatedCost;

                if (! $ingredientTotals->has($ingredient->id)) {
                    $ingredientTotals->put($ingredient->id, [
                        'ingredient_id' => $ingredient->id,
                        'ingredient_name' => $ingredient->name,
                        'required_quantity' => 0.0,
                        'base_unit' => $baseUnit,
                        'unit_price' => $unitPrice,
                        'estimated_cost' => 0.0,
                    ]);
                }

                $current = $ingredientTotals->get($ingredient->id);
                $current['required_quantity'] = round((float) $current['required_quantity'] + $requiredQuantity, 3);
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
                'desired_units' => (float) $line['desired_units'],
                'units' => (float) $line['desired_units'],
                'units_per_batch' => $unitsPerBatch,
                'batches_required' => $batchesRequired,
                'produced_units' => $producedUnits,
                'extra_units' => $extraUnits,
                'batch_size_kg' => $batchSizeKg,
                'oils_kg' => $oilsWeightKg,
                'estimated_cost' => round($lineEstimatedCost, 2),
                'cost_per_unit' => $producedUnits > 0 ? round($lineEstimatedCost / $producedUnits, 4) : 0,
                'batch_preset_name' => $batchConfiguration['batch_preset_name'],
                'duration_per_batch_minutes' => $durationPerBatch,
                'total_duration_minutes' => $totalDuration,
            ]);
        }

        $ingredientTotals = $ingredientTotals
            ->values()
            ->sortByDesc('required_quantity')
            ->values();

        return [
            'product_lines' => $productLines,
            'ingredient_totals' => $ingredientTotals,
            'warnings' => $warnings,
            'totals' => [
                'products_count' => $productLines->count(),
                'total_units' => (float) $productLines->sum('desired_units'),
                'total_desired_units' => (float) $productLines->sum('desired_units'),
                'total_produced_units' => (float) $productLines->sum('produced_units'),
                'total_extra_units' => (float) $productLines->sum('extra_units'),
                'total_batches' => (int) $productLines->sum('batches_required'),
                'total_batch_kg' => round((float) $productLines->sum('oils_kg'), 3),
                'total_estimated_cost' => round((float) $ingredientTotals->sum('estimated_cost'), 2),
                'total_duration_minutes' => (int) $productLines->sum('total_duration_minutes'),
            ],
        ];
    }

    /**
     * Resolves the active formula to use for simulation.
     */
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

    /**
     * Resolves units-per-batch and oils-per-batch from selected preset or product type defaults.
     *
     * @return array{units_per_batch: float, batch_size_kg: float, batch_preset_name: string|null}
     */
    private function resolveBatchConfiguration(Product $product, ?int $batchSizePresetId): array
    {
        $productType = $product->productType;

        if (! $productType) {
            return [
                'units_per_batch' => 0,
                'batch_size_kg' => 0,
                'batch_preset_name' => null,
            ];
        }

        $productType->loadMissing('batchSizePresets');

        /** @var BatchSizePreset|null $preset */
        $preset = null;

        if ($batchSizePresetId) {
            $preset = $productType->batchSizePresets
                ->first(fn (BatchSizePreset $candidate): bool => $candidate->id === $batchSizePresetId);
        }

        if (! $preset) {
            $preset = $productType->batchSizePresets
                ->first(fn (BatchSizePreset $candidate): bool => (bool) $candidate->is_default);
        }

        if (! $preset) {
            $preset = $productType->batchSizePresets->sortByDesc('expected_units')->first();
        }

        return [
            'units_per_batch' => (float) ($preset?->expected_units ?? $productType->expected_units_output ?? 0),
            'batch_size_kg' => (float) ($preset?->batch_size ?? $productType->default_batch_size ?? 0),
            'batch_preset_name' => $preset?->name,
        ];
    }

    /**
     * Resolves total duration per batch from task template.
     */
    private function resolveDurationPerBatch(?TaskTemplate $taskTemplate): int
    {
        if (! $taskTemplate) {
            return 0;
        }

        return (int) $taskTemplate->items()->sum('duration_minutes');
    }

    /**
     * @return array{product_lines: \Illuminate\Support\Collection, ingredient_totals: \Illuminate\Support\Collection, warnings: \Illuminate\Support\Collection, totals: array}
     */
    private function emptyResult(): array
    {
        return [
            'product_lines' => collect(),
            'ingredient_totals' => collect(),
            'warnings' => collect(),
            'totals' => [
                'products_count' => 0,
                'total_units' => 0,
                'total_desired_units' => 0,
                'total_produced_units' => 0,
                'total_extra_units' => 0,
                'total_batches' => 0,
                'total_batch_kg' => 0,
                'total_estimated_cost' => 0,
                'total_duration_minutes' => 0,
            ],
        ];
    }
}
