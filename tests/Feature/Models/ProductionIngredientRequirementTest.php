<?php

use App\Enums\RequirementStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionIngredientRequirement;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ProductionIngredientRequirement Model', function () {
    it('can be created with factory', function () {
        $requirement = ProductionIngredientRequirement::factory()->create();

        expect($requirement)
            ->toBeInstanceOf(ProductionIngredientRequirement::class)
            ->and((float) $requirement->required_quantity)->toBeGreaterThan(0);
    });

    it('belongs to a production', function () {
        $production = Production::factory()->create();
        $requirement = ProductionIngredientRequirement::factory()->create(['production_id' => $production->id]);

        expect($requirement->production->id)->toBe($production->id);
    });

    it('belongs to a wave', function () {
        $wave = ProductionWave::factory()->create();
        $requirement = ProductionIngredientRequirement::factory()->create(['production_wave_id' => $wave->id]);

        expect($requirement->wave->id)->toBe($wave->id);
    });

    it('belongs to an ingredient', function () {
        $ingredient = Ingredient::factory()->create();
        $requirement = ProductionIngredientRequirement::factory()->create(['ingredient_id' => $ingredient->id]);

        expect($requirement->ingredient->id)->toBe($ingredient->id);
    });

    it('can be allocated from a supply', function () {
        $supply = Supply::factory()->create();
        $requirement = ProductionIngredientRequirement::factory()->create(['allocated_from_supply_id' => $supply->id]);

        expect($requirement->allocatedFromSupply->id)->toBe($supply->id);
    });

    it('can be fulfilled by masterbatch', function () {
        $masterbatch = Production::factory()->create();
        $requirement = ProductionIngredientRequirement::factory()
            ->fulfilledByMasterbatch($masterbatch)
            ->create();

        expect($requirement->isFulfilledByMasterbatch())->toBeTrue()
            ->and($requirement->fulfilledByMasterbatch->id)->toBe($masterbatch->id);
    });

    it('casts status as enum', function () {
        $requirement = ProductionIngredientRequirement::factory()->create(['status' => RequirementStatus::NotOrdered]);

        expect($requirement->status)->toBeInstanceOf(RequirementStatus::class)
            ->and($requirement->status)->toBe(RequirementStatus::NotOrdered);
    });

    it('can check if allocated', function () {
        $requirement = ProductionIngredientRequirement::factory()->allocated()->create();

        expect($requirement->isAllocated())->toBeTrue();
    });

    it('calculates remaining quantity', function () {
        $requirement = ProductionIngredientRequirement::factory()->create([
            'required_quantity' => 20.0,
            'allocated_quantity' => 5.0,
        ]);

        expect((float) $requirement->getRemainingQuantity())->toBe(15.0);
    });
});
