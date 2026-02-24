<?php

use App\Enums\ProductionStatus;
use App\Enums\RequirementStatus;
use App\Enums\SizingMode;
use App\Models\Production\Production;
use App\Models\Production\ProductionIngredientRequirement;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;

describe('Production Model', function () {
    it('can be created with factory', function () {
        $production = Production::factory()->create();

        expect($production)
            ->toBeInstanceOf(Production::class)
            ->and($production->batch_number)->not->toBeEmpty()
            ->and($production->slug)->not->toBeEmpty();
    });

    it('has planned status by default', function () {
        $production = Production::factory()->create();

        expect($production->status)->toBe(ProductionStatus::Planned);
    });

    it('can be an orphan production', function () {
        $production = Production::factory()->orphan()->create();

        expect($production->isOrphan())->toBeTrue()
            ->and($production->production_wave_id)->toBeNull();
    });

    it('can belong to a wave', function () {
        $wave = ProductionWave::factory()->create();
        $production = Production::factory()->forWave($wave)->create();

        expect($production->isOrphan())->toBeFalse()
            ->and($production->production_wave_id)->toBe($wave->id)
            ->and($production->wave->id)->toBe($wave->id);
    });

    it('can be a masterbatch', function () {
        $production = Production::factory()->masterbatch()->create();

        expect($production->isMasterbatch())->toBeTrue()
            ->and($production->is_masterbatch)->toBeTrue()
            ->and($production->replaces_phase)->toBe('saponified_oils');
    });

    it('can use a masterbatch', function () {
        $masterbatch = Production::factory()->masterbatch()->finished()->create();
        $production = Production::factory()->usingMasterbatch($masterbatch)->create();

        expect($production->usesMasterbatch())->toBeTrue()
            ->and($production->masterbatch_lot_id)->toBe($masterbatch->id);
    });

    it('can have different statuses', function () {
        $planned = Production::factory()->planned()->create();
        $confirmed = Production::factory()->confirmed()->create();
        $inProgress = Production::factory()->inProgress()->create();
        $finished = Production::factory()->finished()->create();

        expect($planned->status)->toBe(ProductionStatus::Planned)
            ->and($confirmed->status)->toBe(ProductionStatus::Confirmed)
            ->and($inProgress->status)->toBe(ProductionStatus::Ongoing)
            ->and($finished->status)->toBe(ProductionStatus::Finished);
    });

    it('rejects invalid status transitions', function () {
        $production = Production::factory()->inProgress()->create();

        expect(fn () => $production->update(['status' => ProductionStatus::Planned]))
            ->toThrow(InvalidArgumentException::class, 'Invalid production status transition from ongoing to planned.');
    });

    it('allows valid status transitions', function () {
        $production = Production::factory()->planned()->create();

        $production->update(['status' => ProductionStatus::Confirmed]);
        $production->update(['status' => ProductionStatus::Ongoing]);
        $production->update(['status' => ProductionStatus::Finished]);

        expect($production->fresh()->status)->toBe(ProductionStatus::Finished);
    });

    it('can have product type defaults applied', function () {
        $productType = ProductType::factory()->soap()->create();
        $production = Production::factory()->withProductType($productType)->create();

        expect($production->product_type_id)->toBe($productType->id)
            ->and($production->sizing_mode)->toBe(SizingMode::OilWeight)
            ->and((float) $production->planned_quantity)->toBe(26.0)
            ->and($production->expected_units)->toBe(288);
    });

    it('formats lot label with permanent number when available', function () {
        $production = Production::factory()->create([
            'batch_number' => 'B-PLAN-001',
            'permanent_batch_number' => '000321',
        ]);

        expect($production->getLotIdentifier())->toBe('000321')
            ->and($production->getLotDisplayLabel())->toBe('000321 (plan B-PLAN-001)');
    });

    it('computes supply coverage traffic light states', function () {
        $production = Production::factory()->create();

        ProductionIngredientRequirement::factory()->count(2)->create([
            'production_id' => $production->id,
            'status' => RequirementStatus::NotOrdered,
        ]);

        expect($production->fresh()->getSupplyCoverageState())->toBe('missing');

        $production->ingredientRequirements()->update([
            'status' => RequirementStatus::Ordered,
        ]);

        expect($production->fresh()->getSupplyCoverageState())->toBe('ordered');

        $production->ingredientRequirements()->update([
            'status' => RequirementStatus::Received,
        ]);

        expect($production->fresh()->getSupplyCoverageState())->toBe('received');
    });
});

describe('Production Relationships', function () {
    it('belongs to a product', function () {
        $production = Production::factory()->create();

        expect($production->product)->not->toBeNull();
    });

    it('belongs to a formula', function () {
        $production = Production::factory()->create();

        expect($production->formula)->not->toBeNull();
    });

    it('can have ingredient requirements', function () {
        $production = Production::factory()->create();

        expect($production->ingredientRequirements())->not->toBeNull();
    });

    it('can have production tasks', function () {
        $production = Production::factory()->create();

        expect($production->productionTasks())->not->toBeNull();
    });

    it('can reference a manufactured ingredient output', function () {
        $ingredient = Ingredient::factory()->manufactured()->create();

        $production = Production::factory()->masterbatch()->create([
            'produced_ingredient_id' => $ingredient->id,
        ]);

        expect($production->producedIngredient)->not->toBeNull()
            ->and($production->producedIngredient->id)->toBe($ingredient->id);
    });

    it('can link to produced supply lot', function () {
        $production = Production::factory()->create();
        $supply = Supply::factory()->create([
            'source_production_id' => $production->id,
        ]);

        expect($production->producedSupply)->not->toBeNull()
            ->and($production->producedSupply->id)->toBe($supply->id);
    });
});

describe('Masterbatch Productions', function () {
    it('can be used by other productions', function () {
        $masterbatch = Production::factory()->masterbatch()->finished()->create();
        $soap1 = Production::factory()->usingMasterbatch($masterbatch)->create();
        $soap2 = Production::factory()->usingMasterbatch($masterbatch)->create();

        expect($masterbatch->usedInProductions)->toHaveCount(2);
    });
});
