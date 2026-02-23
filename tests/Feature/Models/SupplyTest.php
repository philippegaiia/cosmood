<?php

use App\Models\Production\ProductionIngredientRequirement;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\Supply;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Supply Model', function () {
    it('can be created with factory', function () {
        $supply = Supply::factory()->create();

        expect($supply)
            ->toBeInstanceOf(Supply::class)
            ->and($supply->batch_number)->not->toBeEmpty();
    });

    it('belongs to a supplier listing', function () {
        $listing = SupplierListing::factory()->create();
        $supply = Supply::factory()->create(['supplier_listing_id' => $listing->id]);

        expect($supply->supplierListing->id)->toBe($listing->id);
    });

    it('has many ingredient requirements', function () {
        $supply = Supply::factory()->create();
        ProductionIngredientRequirement::factory()->count(2)->create(['allocated_from_supply_id' => $supply->id]);

        expect($supply->ingredientRequirements)->toHaveCount(2);
    });

    it('calculates available quantity', function () {
        $supply = Supply::factory()->create([
            'quantity_in' => 50.0,
            'quantity_out' => 10.0,
            'allocated_quantity' => 15.0,
        ]);

        expect($supply->getAvailableQuantity())->toBe(25.0);
    });

    it('calculates total quantity', function () {
        $supply = Supply::factory()->create([
            'quantity_in' => 50.0,
            'quantity_out' => 10.0,
        ]);

        expect($supply->getTotalQuantity())->toBe(40.0);
    });

    it('can be in stock', function () {
        $supply = Supply::factory()->inStock(100.0)->create();

        expect($supply->is_in_stock)->toBeTrue()
            ->and((float) $supply->quantity_in)->toBe(100.0);
    });

    it('can be partially allocated', function () {
        $supply = Supply::factory()->partiallyAllocated(50.0, 20.0)->create();

        expect((float) $supply->allocated_quantity)->toBe(20.0)
            ->and($supply->getAvailableQuantity())->toBe(30.0);
    });

    it('can be out of stock', function () {
        $supply = Supply::factory()->outOfStock()->create();

        expect($supply->is_in_stock)->toBeFalse();
    });

    it('can be expired', function () {
        $supply = Supply::factory()->expired()->create();

        expect($supply->expiry_date->isPast())->toBeTrue();
    });
});
