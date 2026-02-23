<?php

use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Formula Model', function () {
    it('can be created with factory', function () {
        $formula = Formula::factory()->create();

        expect($formula)
            ->toBeInstanceOf(Formula::class)
            ->and($formula->name)->not->toBeEmpty();
    });

    it('belongs to a product', function () {
        $product = Product::factory()->create();
        $formula = Formula::factory()->create(['product_id' => $product->id]);

        expect($formula->product->id)->toBe($product->id);
    });

    it('has many formula items', function () {
        $formula = Formula::factory()->create();
        FormulaItem::factory()->count(3)->create(['formula_id' => $formula->id]);

        expect($formula->formulaItems)->toHaveCount(3);
    });

    it('has many productions', function () {
        $formula = Formula::factory()->create();
        \App\Models\Production\Production::factory()->count(2)->create(['formula_id' => $formula->id]);

        expect($formula->productions)->toHaveCount(2);
    });

    it('can be a masterbatch formula', function () {
        $formula = Formula::factory()->masterbatch()->create();

        expect($formula->isMasterbatchFormula())->toBeTrue()
            ->and($formula->replaces_phase)->toBe('saponified_oils');
    });

    it('is not a masterbatch formula by default', function () {
        $formula = Formula::factory()->create();

        expect($formula->isMasterbatchFormula())->toBeFalse();
    });

    it('casts is_active as boolean', function () {
        $formula = Formula::factory()->create(['is_active' => true]);

        expect($formula->is_active)->toBeTrue();
    });

    it('casts date_of_creation as date', function () {
        $formula = Formula::factory()->create();

        expect($formula->date_of_creation)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });
});
