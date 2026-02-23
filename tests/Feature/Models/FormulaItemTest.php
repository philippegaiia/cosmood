<?php

use App\Enums\Phases;
use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Supply\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('FormulaItem Model', function () {
    it('can be created with factory', function () {
        $formulaItem = FormulaItem::factory()->create();

        expect($formulaItem)
            ->toBeInstanceOf(FormulaItem::class)
            ->and((float) $formulaItem->percentage_of_oils)->toBeGreaterThan(0);
    });

    it('belongs to a formula', function () {
        $formula = Formula::factory()->create();
        $formulaItem = FormulaItem::factory()->create(['formula_id' => $formula->id]);

        expect($formulaItem->formula->id)->toBe($formula->id);
    });

    it('belongs to an ingredient', function () {
        $ingredient = Ingredient::factory()->create();
        $formulaItem = FormulaItem::factory()->create(['ingredient_id' => $ingredient->id]);

        expect($formulaItem->ingredient->id)->toBe($ingredient->id);
    });

    it('casts phase as enum', function () {
        $formulaItem = FormulaItem::factory()->create(['phase' => Phases::Saponification]);

        expect($formulaItem->phase)->toBeInstanceOf(Phases::class)
            ->and($formulaItem->phase)->toBe(Phases::Saponification);
    });

    it('can have saponified phase', function () {
        $formulaItem = FormulaItem::factory()->saponified()->create();

        expect($formulaItem->phase)->toBe(Phases::Saponification);
    });

    it('can have additive phase', function () {
        $formulaItem = FormulaItem::factory()->additive()->create();

        expect($formulaItem->phase)->toBe(Phases::Additives);
    });
});
