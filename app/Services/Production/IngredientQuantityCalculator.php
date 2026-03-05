<?php

namespace App\Services\Production;

use App\Enums\FormulaItemCalculationMode;
use App\Enums\IngredientBaseUnit;

/**
 * Centralized ingredient quantity calculation.
 *
 * Calculation mode resolution (in order of priority):
 * 1. Unit-based ingredient (base_unit = 'u') → QuantityPerUnit
 * 2. Explicit calculation_mode on item → use that
 * 3. Default → PercentOfOils
 *
 * Phase does not influence calculation mode - it's determined solely by
 * the ingredient's base unit or explicit mode setting.
 */
class IngredientQuantityCalculator
{
    /**
     * Resolves the calculation mode from ingredient base unit and stored mode.
     */
    public function resolveCalculationMode(
        IngredientBaseUnit|string|null $ingredientBaseUnit,
        FormulaItemCalculationMode|string|null $storedMode,
    ): FormulaItemCalculationMode {
        $baseUnit = $this->normalizeBaseUnit($ingredientBaseUnit);

        if ($baseUnit === IngredientBaseUnit::Unit) {
            return FormulaItemCalculationMode::QuantityPerUnit;
        }

        if ($storedMode instanceof FormulaItemCalculationMode) {
            return $storedMode;
        }

        $mode = FormulaItemCalculationMode::tryFrom((string) ($storedMode ?? ''));

        if ($mode) {
            return $mode;
        }

        return FormulaItemCalculationMode::PercentOfOils;
    }

    /**
     * Calculates the required quantity based on the calculation mode.
     *
     * @param  float  $coefficient  The percentage or qty-per-unit value
     * @param  float  $batchSizeKg  The batch size in kg (for PercentOfOils)
     * @param  int|float|null  $expectedUnits  The expected units (for QuantityPerUnit)
     */
    public function calculate(
        float $coefficient,
        float $batchSizeKg,
        int|float|null $expectedUnits,
        FormulaItemCalculationMode $calculationMode,
    ): float {
        if ($calculationMode === FormulaItemCalculationMode::QuantityPerUnit) {
            $quantity = round(((float) ($expectedUnits ?? 0)) * $coefficient, 3);
            $nearestInteger = round($quantity);

            if (abs($quantity - $nearestInteger) <= 0.01) {
                return (float) $nearestInteger;
            }

            return $quantity;
        }

        return round(($coefficient / 100) * $batchSizeKg, 3);
    }

    /**
     * Convenience method: resolve mode and calculate in one call.
     */
    public function resolveAndCalculate(
        float $coefficient,
        float $batchSizeKg,
        int|float|null $expectedUnits,
        IngredientBaseUnit|string|null $ingredientBaseUnit,
        FormulaItemCalculationMode|string|null $storedMode,
    ): float {
        $mode = $this->resolveCalculationMode($ingredientBaseUnit, $storedMode);

        return $this->calculate($coefficient, $batchSizeKg, $expectedUnits, $mode);
    }

    private function normalizeBaseUnit(IngredientBaseUnit|string|null $baseUnit): ?IngredientBaseUnit
    {
        if ($baseUnit instanceof IngredientBaseUnit) {
            return $baseUnit;
        }

        return IngredientBaseUnit::tryFrom((string) ($baseUnit ?? ''));
    }
}
