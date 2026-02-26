<?php

use App\Enums\FormulaItemCalculationMode;
use App\Enums\Phases;
use App\Enums\RequirementStatus;
use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Services\Production\ProductionRequirementsService;

describe('ProductionRequirementsService', function () {
    beforeEach(function () {
        $this->service = app(ProductionRequirementsService::class);
    });

    describe('generateRequirements', function () {
        it('can generate requirements from a production', function () {
            $formula = Formula::factory()->create();
            $ingredient1 = Ingredient::factory()->create();
            $ingredient2 = Ingredient::factory()->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($ingredient1)
                ->saponified()
                ->percentage(50)
                ->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($ingredient2)
                ->saponified()
                ->percentage(50)
                ->create();

            $production = Production::factory()->create([
                'formula_id' => $formula->id,
                'planned_quantity' => 26.0,
            ]);

            $this->service->generateRequirements($production);

            $requirements = $production->fresh()->ingredientRequirements;

            expect($requirements)->toHaveCount(2)
                ->and($requirements->first()->ingredient_id)->toBe($ingredient1->id)
                ->and((float) $requirements->first()->required_quantity)->toBe(13.0)
                ->and($requirements->first()->status)->toBe(RequirementStatus::NotOrdered);
        });

        it('calculates quantities based on percentage of oils', function () {
            $formula = Formula::factory()->create();
            $ingredient = Ingredient::factory()->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($ingredient)
                ->saponified()
                ->percentage(30)
                ->create();

            $production = Production::factory()->create([
                'formula_id' => $formula->id,
                'planned_quantity' => 26.0,
            ]);

            $this->service->generateRequirements($production);

            $requirement = $production->ingredientRequirements->first();

            expect((float) $requirement->required_quantity)->toBe(7.8);
        });

        it('groups requirements by phase', function () {
            $formula = Formula::factory()->create();
            $oilIngredient = Ingredient::factory()->create();
            $lyeIngredient = Ingredient::factory()->create();
            $additiveIngredient = Ingredient::factory()->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($oilIngredient)
                ->saponified()
                ->percentage(100)
                ->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($lyeIngredient)
                ->lye()
                ->percentage(35)
                ->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($additiveIngredient)
                ->additive()
                ->percentage(5)
                ->create();

            $production = Production::factory()->create([
                'formula_id' => $formula->id,
                'planned_quantity' => 26.0,
            ]);

            $this->service->generateRequirements($production);

            $requirements = $production->ingredientRequirements;

            $saponifiedReq = $requirements->where('phase', Phases::Saponification->value)->first();
            $lyeReq = $requirements->where('phase', Phases::Lye->value)->first();
            $additiveReq = $requirements->where('phase', Phases::Additives->value)->first();

            expect($saponifiedReq)->not->toBeNull()
                ->and((float) $saponifiedReq->required_quantity)->toBe(26.0)
                ->and((float) $lyeReq->required_quantity)->toBe(9.1)
                ->and((float) $additiveReq->required_quantity)->toBe(1.3);
        });

        it('calculates packaging requirements from expected units using line coefficient', function () {
            $formula = Formula::factory()->create();
            $packagingIngredient = Ingredient::factory()->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($packagingIngredient)
                ->state([
                    'phase' => Phases::Packaging->value,
                    'calculation_mode' => FormulaItemCalculationMode::QuantityPerUnit->value,
                    'percentage_of_oils' => 1,
                ])
                ->create();

            $production = Production::factory()->create([
                'formula_id' => $formula->id,
                'planned_quantity' => 26.0,
                'expected_units' => 288,
            ]);

            $this->service->generateRequirements($production);

            $requirement = $production->fresh()->ingredientRequirements->first();

            expect($requirement)->not->toBeNull()
                ->and($requirement->phase)->toBe(Phases::Packaging->value)
                ->and((float) $requirement->required_quantity)->toBe(288.0);
        });

        it('calculates unit-based requirements independently from phase when mode is qty per unit', function () {
            $formula = Formula::factory()->create();
            $ingredient = Ingredient::factory()->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($ingredient)
                ->state([
                    'phase' => Phases::Additives->value,
                    'calculation_mode' => FormulaItemCalculationMode::QuantityPerUnit->value,
                    'percentage_of_oils' => 0.5,
                ])
                ->create();

            $production = Production::factory()->create([
                'formula_id' => $formula->id,
                'planned_quantity' => 26.0,
                'expected_units' => 288,
            ]);

            $this->service->generateRequirements($production);

            $requirement = $production->fresh()->ingredientRequirements->first();

            expect($requirement)->not->toBeNull()
                ->and((float) $requirement->required_quantity)->toBe(144.0);
        });

        it('links requirements to wave if production has wave', function () {
            $wave = ProductionWave::factory()->create();
            $formula = Formula::factory()->create();
            $ingredient = Ingredient::factory()->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($ingredient)
                ->saponified()
                ->percentage(100)
                ->create();

            $production = Production::factory()->create([
                'formula_id' => $formula->id,
                'planned_quantity' => 26.0,
                'production_wave_id' => $wave->id,
            ]);

            $this->service->generateRequirements($production);

            $requirement = $production->ingredientRequirements->first();

            expect($requirement->production_wave_id)->toBe($wave->id);
        });

        it('does not duplicate requirements on repeated calls', function () {
            $formula = Formula::factory()->create();
            $ingredient = Ingredient::factory()->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($ingredient)
                ->saponified()
                ->percentage(100)
                ->create();

            $production = Production::factory()->create([
                'formula_id' => $formula->id,
                'planned_quantity' => 26.0,
            ]);

            $this->service->generateRequirements($production);
            $this->service->generateRequirements($production);

            expect($production->ingredientRequirements)->toHaveCount(1);
        });
    });

    describe('regenerateRequirements', function () {
        it('removes existing requirements and regenerates', function () {
            $formula = Formula::factory()->create();
            $ingredient = Ingredient::factory()->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($ingredient)
                ->saponified()
                ->percentage(100)
                ->create();

            $production = Production::factory()->create([
                'formula_id' => $formula->id,
                'planned_quantity' => 26.0,
            ]);

            $this->service->generateRequirements($production);

            $production->update(['planned_quantity' => 50.0]);
            $production->refresh();

            $this->service->regenerateRequirements($production);

            $requirement = $production->fresh()->ingredientRequirements->first();

            expect($production->fresh()->ingredientRequirements)->toHaveCount(1)
                ->and((float) $requirement->required_quantity)->toBe(50.0);
        });
    });

    describe('getTotalOilsWeight', function () {
        it('calculates total oils weight for saponified phase', function () {
            $formula = Formula::factory()->create();
            $ingredient1 = Ingredient::factory()->create();
            $ingredient2 = Ingredient::factory()->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($ingredient1)
                ->saponified()
                ->percentage(60)
                ->create();

            FormulaItem::factory()->forFormula($formula)
                ->withIngredient($ingredient2)
                ->saponified()
                ->percentage(40)
                ->create();

            $totalOils = $this->service->getTotalOilsPercentage($formula);

            expect((float) $totalOils)->toBe(100.0);
        });
    });
});
