<?php

use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('SupplierListing Model', function () {
    it('can be created with factory', function () {
        $listing = SupplierListing::factory()->create();

        expect($listing)
            ->toBeInstanceOf(SupplierListing::class)
            ->and($listing->name)->not->toBeEmpty();
    });

    it('belongs to a supplier', function () {
        $supplier = Supplier::factory()->create();
        $listing = SupplierListing::factory()->create(['supplier_id' => $supplier->id]);

        expect($listing->supplier->id)->toBe($supplier->id);
    });

    it('belongs to an ingredient', function () {
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);

        expect($listing->ingredient->id)->toBe($ingredient->id);
    });

    it('can be marked as organic', function () {
        $listing = SupplierListing::factory()->organic()->create();

        expect($listing->organic)->toBeTrue();
    });

    it('can be marked as inactive', function () {
        $listing = SupplierListing::factory()->inactive()->create();

        expect($listing->is_active)->toBeFalse();
    });
});
