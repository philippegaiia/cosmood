<?php

use App\Models\Production\FormulaProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('FormulaProduct Model', function () {
    it('uses formula_product table', function () {
        $model = new FormulaProduct;

        expect($model->getTable())->toBe('formula_product');
    });

    it('can be created and soft deleted', function () {
        $record = FormulaProduct::create();

        expect($record)->toBeInstanceOf(FormulaProduct::class);

        $record->delete();

        expect($record->fresh()->deleted_at)->not->toBeNull();
    });
});
