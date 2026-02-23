<?php

use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierContact;
use App\Models\Supply\SupplierListing;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Supplier Model', function () {
    it('can be created with factory', function () {
        $supplier = Supplier::factory()->create();

        expect($supplier)
            ->toBeInstanceOf(Supplier::class)
            ->and($supplier->name)->not->toBeEmpty();
    });

    it('has many contacts', function () {
        $supplier = Supplier::factory()->create();
        SupplierContact::factory()->count(2)->create(['supplier_id' => $supplier->id]);

        expect($supplier->contacts)->toHaveCount(2);
    });

    it('has many supplier listings', function () {
        $supplier = Supplier::factory()->create();
        SupplierListing::factory()->count(3)->create(['supplier_id' => $supplier->id]);

        expect($supplier->supplier_listings)->toHaveCount(3);
    });

    it('can be marked as inactive', function () {
        $supplier = Supplier::factory()->inactive()->create();

        expect($supplier->is_active)->toBeFalse();
    });
});
