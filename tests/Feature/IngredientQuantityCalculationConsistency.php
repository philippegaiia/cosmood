<?php

use App\Enums\FormulaItemCalculationMode;
use App\Enums\IngredientBaseUnit;
use App\Enums\Phases;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Supply\Ingredient;
use App\Services\Production\ProductionRequirementsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Ingredient Quantity Calculation Consistency', function () {
    beforeEach(function () {
        $this->requirementsService = app(ProductionRequirementsService::class);
    });

    it('matches percent of oils calculation between ProductionItem and RequirementsService', function () {
        $production = Production::factory()->create([
            'planned_quantity' => 100.0,
            'expected_units' => 500,
        ]);

        $ingredient = Ingredient::factory()->create([
            'base_unit' => IngredientBaseUnit::Kg->value,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'phase' => Phases::Saponification->value,
            'calculation_mode' => FormulaItemCalculationMode::PercentOfOils->value,
            'percentage_of_oils' => 25.0,
        ]);

        $itemQuantity = $item->getCalculatedQuantityKg($production);

        $serviceQuantity = $this->requirementsService->calculateQuantity(
            percentage: 25.0,
            batchSize: 100.0,
            calculationMode: FormulaItemCalculationMode::PercentOfOils->value,
            ingredientBaseUnit: IngredientBaseUnit::Kg->value,
            expectedUnits: 500,
        );

        expect($itemQuantity)->toBe($serviceQuantity)
            ->and($itemQuantity)->toBe(25.0);
    });

    it('matches quantity per unit for unit-based ingredient', function () {
        $production = Production::factory()->create([
            'planned_quantity' => 50.0,
            'expected_units' => 300,
        ]);

        $ingredient = Ingredient::factory()->create([
            'base_unit' => IngredientBaseUnit::Unit->value,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'phase' => Phases::Packaging->value,
            'percentage_of_oils' => 1.0,
        ]);

        $itemQuantity = $item->getCalculatedQuantityKg($production);

        $serviceQuantity = $this->requirementsService->calculateQuantity(
            percentage: 1.0,
            batchSize: 50.0,
            calculationMode: null,
            ingredientBaseUnit: IngredientBaseUnit::Unit->value,
            expectedUnits: 300,
        );

        expect($itemQuantity)->toBe($serviceQuantity)
            ->and($itemQuantity)->toBe(300.0);
    });

    it('matches quantity per unit calculation when explicitly set', function () {
        $production = Production::factory()->create([
            'planned_quantity' => 80.0,
            'expected_units' => 400,
        ]);

        $ingredient = Ingredient::factory()->create([
            'base_unit' => IngredientBaseUnit::Kg->value,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'phase' => Phases::Additives->value,
            'calculation_mode' => FormulaItemCalculationMode::QuantityPerUnit->value,
            'percentage_of_oils' => 0.5,
        ]);

        $itemQuantity = $item->getCalculatedQuantityKg($production);

        $serviceQuantity = $this->requirementsService->calculateQuantity(
            percentage: 0.5,
            batchSize: 80.0,
            calculationMode: FormulaItemCalculationMode::QuantityPerUnit->value,
            ingredientBaseUnit: IngredientBaseUnit::Kg->value,
            expectedUnits: 400,
        );

        expect($itemQuantity)->toBe($serviceQuantity)
            ->and($itemQuantity)->toBe(200.0);
    });
});
