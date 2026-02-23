<?php

use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierContact;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('SupplierContact Model', function () {
    it('can be created with factory', function () {
        $contact = SupplierContact::factory()->create();

        expect($contact)->toBeInstanceOf(SupplierContact::class)
            ->and($contact->email)->not->toBeEmpty();
    });

    it('belongs to a supplier', function () {
        $supplier = Supplier::factory()->create();
        $contact = SupplierContact::factory()->create(['supplier_id' => $supplier->id]);

        expect($contact->supplier->id)->toBe($supplier->id);
    });
});
