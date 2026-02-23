<?php

use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Enums\RequirementStatus;
use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Production;
use App\Models\Production\ProductionIngredientRequirement;
use App\Models\Production\ProductionItem;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\Supply;
use App\Services\Production\MasterbatchService;
use Illuminate\Support\Str;

describe('MasterbatchService', function () {
    beforeEach(function () {
        $this->service = app(MasterbatchService::class);
    });

    describe('selectMasterbatch', function () {
        it('can select a masterbatch for a production', function () {
            $mbFormula = Formula::factory()->create([
                'replaces_phase' => Phases::Saponification->value,
                'code' => 'MB-'.Str::random(8),
            ]);
            $masterbatch = Production::factory()->masterbatch()->finished()->create([
                'formula_id' => $mbFormula->id,
                'replaces_phase' => Phases::Saponification->value,
            ]);

            $soapFormula = Formula::factory()->create(['code' => 'SOAP-'.Str::random(8)]);
            $ingredient = Ingredient::factory()->create();
            FormulaItem::factory()->forFormula($soapFormula)
                ->withIngredient($ingredient)
                ->saponified()
                ->percentage(100)
                ->create();

            $production = Production::factory()->create([
                'formula_id' => $soapFormula->id,
                'planned_quantity' => 26.0,
            ]);

            ProductionIngredientRequirement::create([
                'production_id' => $production->id,
                'ingredient_id' => $ingredient->id,
                'phase' => Phases::Saponification->value,
                'required_quantity' => 26.0,
                'status' => RequirementStatus::NotOrdered,
            ]);

            $this->service->selectMasterbatch($production, $masterbatch);

            expect($production->fresh()->masterbatch_lot_id)->toBe($masterbatch->id);
        });

        it('collapses requirements for the replaced phase', function () {
            $mbFormula = Formula::factory()->create([
                'replaces_phase' => Phases::Saponification->value,
                'code' => 'MB-'.Str::random(8),
            ]);
            $masterbatch = Production::factory()->masterbatch()->finished()->create([
                'formula_id' => $mbFormula->id,
                'replaces_phase' => Phases::Saponification->value,
            ]);

            $soapFormula = Formula::factory()->create(['code' => 'SOAP-'.Str::random(8)]);
            $oil1 = Ingredient::factory()->create();
            $oil2 = Ingredient::factory()->create();
            $lye = Ingredient::factory()->create();

            FormulaItem::factory()->forFormula($soapFormula)->withIngredient($oil1)->saponified()->percentage(60)->create();
            FormulaItem::factory()->forFormula($soapFormula)->withIngredient($oil2)->saponified()->percentage(40)->create();
            FormulaItem::factory()->forFormula($soapFormula)->withIngredient($lye)->lye()->percentage(35)->create();

            $production = Production::factory()->create([
                'formula_id' => $soapFormula->id,
                'planned_quantity' => 26.0,
            ]);

            ProductionIngredientRequirement::create([
                'production_id' => $production->id,
                'ingredient_id' => $oil1->id,
                'phase' => Phases::Saponification->value,
                'required_quantity' => 15.6,
                'status' => RequirementStatus::NotOrdered,
            ]);

            ProductionIngredientRequirement::create([
                'production_id' => $production->id,
                'ingredient_id' => $oil2->id,
                'phase' => Phases::Saponification->value,
                'required_quantity' => 10.4,
                'status' => RequirementStatus::NotOrdered,
            ]);

            ProductionIngredientRequirement::create([
                'production_id' => $production->id,
                'ingredient_id' => $lye->id,
                'phase' => Phases::Lye->value,
                'required_quantity' => 9.1,
                'status' => RequirementStatus::NotOrdered,
            ]);

            $this->service->selectMasterbatch($production, $masterbatch);

            $requirements = $production->fresh()->ingredientRequirements;

            $saponifiedReqs = $requirements->where('phase', Phases::Saponification->value);
            $lyeReqs = $requirements->where('phase', Phases::Lye->value);

            expect($saponifiedReqs->every(fn ($r) => $r->fulfilled_by_masterbatch_id === $masterbatch->id))->toBeTrue()
                ->and($saponifiedReqs->every(fn ($r) => $r->is_collapsed_in_ui === true))->toBeTrue()
                ->and($lyeReqs->first()->fulfilled_by_masterbatch_id)->toBeNull();
        });

        it('validates masterbatch compatibility', function () {
            $mbFormula = Formula::factory()->create([
                'replaces_phase' => Phases::Lye->value,
                'code' => 'MB-'.Str::random(8),
            ]);
            $masterbatch = Production::factory()->masterbatch()->finished()->create([
                'formula_id' => $mbFormula->id,
                'replaces_phase' => Phases::Lye->value,
            ]);

            $soapFormula = Formula::factory()->create(['code' => 'SOAP-'.Str::random(8)]);
            $ingredient = Ingredient::factory()->create();
            FormulaItem::factory()->forFormula($soapFormula)->withIngredient($ingredient)->saponified()->create();

            $production = Production::factory()->create(['formula_id' => $soapFormula->id]);

            ProductionIngredientRequirement::create([
                'production_id' => $production->id,
                'ingredient_id' => $ingredient->id,
                'phase' => Phases::Saponification->value,
                'required_quantity' => 10.0,
                'status' => RequirementStatus::NotOrdered,
            ]);

            expect(fn () => $this->service->selectMasterbatch($production, $masterbatch))
                ->toThrow(\InvalidArgumentException::class, 'Masterbatch phase mismatch');
        });

        it('validates masterbatch is finished', function () {
            $mbFormula = Formula::factory()->create([
                'replaces_phase' => Phases::Saponification->value,
                'code' => 'MB-'.Str::random(8),
            ]);
            $masterbatch = Production::factory()->masterbatch()->create([
                'formula_id' => $mbFormula->id,
                'replaces_phase' => Phases::Saponification->value,
                'status' => ProductionStatus::Planned,
            ]);

            $soapFormula = Formula::factory()->create(['code' => 'SOAP-'.Str::random(8)]);
            $ingredient = Ingredient::factory()->create();
            FormulaItem::factory()->forFormula($soapFormula)->withIngredient($ingredient)->saponified()->create();

            $production = Production::factory()->create(['formula_id' => $soapFormula->id]);

            ProductionIngredientRequirement::create([
                'production_id' => $production->id,
                'ingredient_id' => $ingredient->id,
                'phase' => Phases::Saponification->value,
                'required_quantity' => 10.0,
                'status' => RequirementStatus::NotOrdered,
            ]);

            expect(fn () => $this->service->selectMasterbatch($production, $masterbatch))
                ->toThrow(\InvalidArgumentException::class, 'Masterbatch must be finished');
        });
    });

    describe('removeMasterbatch', function () {
        it('can remove masterbatch selection', function () {
            $mbFormula = Formula::factory()->create([
                'replaces_phase' => Phases::Saponification->value,
                'code' => 'MB-'.Str::random(8),
            ]);
            $masterbatch = Production::factory()->masterbatch()->finished()->create([
                'formula_id' => $mbFormula->id,
                'replaces_phase' => Phases::Saponification->value,
            ]);

            $soapFormula = Formula::factory()->create(['code' => 'SOAP-'.Str::random(8)]);
            $ingredient = Ingredient::factory()->create();
            FormulaItem::factory()->forFormula($soapFormula)->withIngredient($ingredient)->saponified()->create();

            $production = Production::factory()->create([
                'formula_id' => $soapFormula->id,
                'masterbatch_lot_id' => $masterbatch->id,
            ]);

            ProductionIngredientRequirement::create([
                'production_id' => $production->id,
                'ingredient_id' => $ingredient->id,
                'phase' => Phases::Saponification->value,
                'required_quantity' => 26.0,
                'status' => RequirementStatus::NotOrdered,
                'fulfilled_by_masterbatch_id' => $masterbatch->id,
                'is_collapsed_in_ui' => true,
            ]);

            $this->service->removeMasterbatch($production);

            $requirement = $production->fresh()->ingredientRequirements->first();

            expect($production->fresh()->masterbatch_lot_id)->toBeNull()
                ->and($requirement->fulfilled_by_masterbatch_id)->toBeNull()
                ->and($requirement->is_collapsed_in_ui)->toBeFalse();
        });
    });

    describe('applyTraceabilityToProductionItems', function () {
        it('copies supply traceability from selected masterbatch to replaced phase items', function () {
            $ingredient = Ingredient::factory()->create();
            $listing = SupplierListing::factory()->create([
                'ingredient_id' => $ingredient->id,
            ]);
            $supply = Supply::factory()->create([
                'supplier_listing_id' => $listing->id,
                'batch_number' => 'LOT-MB-001',
            ]);

            $masterbatch = Production::factory()->masterbatch()->finished()->create([
                'batch_number' => 'MB01',
                'replaces_phase' => 'saponified_oils',
            ]);

            ProductionItem::factory()->create([
                'production_id' => $masterbatch->id,
                'ingredient_id' => $ingredient->id,
                'supplier_listing_id' => $listing->id,
                'supply_id' => $supply->id,
                'supply_batch_number' => 'LOT-MB-001',
                'phase' => Phases::Saponification->value,
                'is_supplied' => true,
            ]);

            $production = Production::factory()->create([
                'masterbatch_lot_id' => $masterbatch->id,
            ]);

            $targetItem = ProductionItem::factory()->create([
                'production_id' => $production->id,
                'ingredient_id' => $ingredient->id,
                'phase' => Phases::Saponification->value,
                'supply_id' => null,
                'supply_batch_number' => null,
                'is_supplied' => false,
            ]);

            $updated = $this->service->applyTraceabilityToProductionItems($production);

            expect($updated)->toBe(1)
                ->and($targetItem->fresh()->supply_id)->toBe($supply->id)
                ->and($targetItem->fresh()->supply_batch_number)->toBe('LOT-MB-001')
                ->and($targetItem->fresh()->is_supplied)->toBeTrue();
        });

        it('copies batch traceability even when masterbatch item has no linked supply id', function () {
            $ingredient = Ingredient::factory()->create();
            $listing = SupplierListing::factory()->create([
                'ingredient_id' => $ingredient->id,
            ]);

            $masterbatch = Production::factory()->masterbatch()->finished()->create([
                'batch_number' => 'MB02',
                'replaces_phase' => 'saponified_oils',
            ]);

            ProductionItem::factory()->create([
                'production_id' => $masterbatch->id,
                'ingredient_id' => $ingredient->id,
                'supplier_listing_id' => $listing->id,
                'supply_id' => null,
                'supply_batch_number' => 'LOT-MB-BATCH-ONLY',
                'phase' => Phases::Saponification->value,
                'is_supplied' => true,
            ]);

            $production = Production::factory()->create([
                'masterbatch_lot_id' => $masterbatch->id,
            ]);

            $targetItem = ProductionItem::factory()->create([
                'production_id' => $production->id,
                'ingredient_id' => $ingredient->id,
                'phase' => Phases::Saponification->value,
                'supply_id' => null,
                'supply_batch_number' => null,
                'is_supplied' => false,
            ]);

            $updated = $this->service->applyTraceabilityToProductionItems($production);

            expect($updated)->toBe(1)
                ->and($targetItem->fresh()->supply_id)->toBeNull()
                ->and($targetItem->fresh()->supply_batch_number)->toBe('LOT-MB-BATCH-ONLY')
                ->and($targetItem->fresh()->is_supplied)->toBeTrue();
        });

        it('detects percentage mismatches between production and masterbatch oils', function () {
            $ingredient = Ingredient::factory()->create();

            $masterbatch = Production::factory()->masterbatch()->finished()->create([
                'replaces_phase' => 'saponified_oils',
            ]);

            ProductionItem::factory()->create([
                'production_id' => $masterbatch->id,
                'ingredient_id' => $ingredient->id,
                'phase' => Phases::Saponification->value,
                'percentage_of_oils' => 62.5,
            ]);

            $production = Production::factory()->create([
                'masterbatch_lot_id' => $masterbatch->id,
            ]);

            ProductionItem::factory()->create([
                'production_id' => $production->id,
                'ingredient_id' => $ingredient->id,
                'phase' => Phases::Saponification->value,
                'percentage_of_oils' => 60,
            ]);

            $mismatches = $this->service->getPercentageMismatches($production);

            expect($mismatches)->toHaveCount(1)
                ->and($mismatches->first()['production_percentage'])->toBe(60.0)
                ->and($mismatches->first()['masterbatch_percentage'])->toBe(62.5);
        });
    });

    describe('getExpandedIngredients', function () {
        it('expands masterbatch ingredients for PDF', function () {
            $mbOil1 = Ingredient::factory()->create();
            $mbOil2 = Ingredient::factory()->create();

            $mbFormula = Formula::factory()->create([
                'replaces_phase' => Phases::Saponification->value,
                'code' => 'MB-'.Str::random(8),
            ]);
            FormulaItem::factory()->forFormula($mbFormula)->withIngredient($mbOil1)->saponified()->percentage(60)->create();
            FormulaItem::factory()->forFormula($mbFormula)->withIngredient($mbOil2)->saponified()->percentage(40)->create();

            $masterbatch = Production::factory()->masterbatch()->finished()->create([
                'formula_id' => $mbFormula->id,
                'replaces_phase' => Phases::Saponification->value,
                'batch_number' => 'MB01',
            ]);

            $soapFormula = Formula::factory()->create(['code' => 'SOAP-'.Str::random(8)]);
            $soapOil = Ingredient::factory()->create();

            FormulaItem::factory()->forFormula($soapFormula)->withIngredient($soapOil)->saponified()->percentage(100)->create();

            $production = Production::factory()->create([
                'formula_id' => $soapFormula->id,
                'masterbatch_lot_id' => $masterbatch->id,
                'planned_quantity' => 26.0,
            ]);

            $expanded = $this->service->getExpandedIngredients($production);

            expect($expanded)->toHaveCount(2)
                ->and($expanded->first()['ingredient_id'])->toBe($mbOil1->id)
                ->and($expanded->first()['masterbatch_batch_number'])->toBe('MB01');
        });
    });

    describe('isMasterbatchCompatible', function () {
        it('returns true when phases match', function () {
            $mbFormula = Formula::factory()->create([
                'replaces_phase' => Phases::Saponification->value,
                'code' => 'MB-'.Str::random(8),
            ]);
            $masterbatch = Production::factory()->masterbatch()->finished()->create([
                'formula_id' => $mbFormula->id,
                'replaces_phase' => Phases::Saponification->value,
            ]);

            $soapFormula = Formula::factory()->create(['code' => 'SOAP-'.Str::random(8)]);
            $ingredient = Ingredient::factory()->create();
            FormulaItem::factory()->forFormula($soapFormula)->withIngredient($ingredient)->saponified()->create();

            $production = Production::factory()->create(['formula_id' => $soapFormula->id]);

            ProductionIngredientRequirement::create([
                'production_id' => $production->id,
                'ingredient_id' => $ingredient->id,
                'phase' => Phases::Saponification->value,
                'required_quantity' => 10.0,
                'status' => RequirementStatus::NotOrdered,
            ]);

            expect($this->service->isMasterbatchCompatible($production, $masterbatch))->toBeTrue();
        });

        it('returns false when phases do not match', function () {
            $mbFormula = Formula::factory()->create([
                'replaces_phase' => Phases::Lye->value,
                'code' => 'MB-'.Str::random(8),
            ]);
            $masterbatch = Production::factory()->masterbatch()->finished()->create([
                'formula_id' => $mbFormula->id,
                'replaces_phase' => Phases::Lye->value,
            ]);

            $soapFormula = Formula::factory()->create(['code' => 'SOAP-'.Str::random(8)]);
            $ingredient = Ingredient::factory()->create();
            FormulaItem::factory()->forFormula($soapFormula)->withIngredient($ingredient)->saponified()->create();

            $production = Production::factory()->create(['formula_id' => $soapFormula->id]);

            ProductionIngredientRequirement::create([
                'production_id' => $production->id,
                'ingredient_id' => $ingredient->id,
                'phase' => Phases::Saponification->value,
                'required_quantity' => 10.0,
                'status' => RequirementStatus::NotOrdered,
            ]);

            expect($this->service->isMasterbatchCompatible($production, $masterbatch))->toBeFalse();
        });
    });
});
