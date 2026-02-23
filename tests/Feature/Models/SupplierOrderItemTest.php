<?php

use App\Models\Production\Production;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('SupplierOrderItem Model', function () {
    it('can be created with factory', function () {
        $item = SupplierOrderItem::factory()->create();

        expect($item)
            ->toBeInstanceOf(SupplierOrderItem::class)
            ->and((float) $item->quantity)->toBeGreaterThan(0);
    });

    it('belongs to a supplier order', function () {
        $order = SupplierOrder::factory()->create();
        $item = SupplierOrderItem::factory()->create(['supplier_order_id' => $order->id]);

        expect($item->supplierOrder->id)->toBe($order->id);
    });

    it('belongs to a supplier listing', function () {
        $listing = SupplierListing::factory()->create();
        $item = SupplierOrderItem::factory()->create(['supplier_listing_id' => $listing->id]);

        expect($item->supplierListing->id)->toBe($listing->id);
    });

    it('can be allocated to a production', function () {
        $production = Production::factory()->create();
        $item = SupplierOrderItem::factory()->allocated($production, 10.0)->create();

        expect($item->allocatedToProduction->id)->toBe($production->id)
            ->and((float) $item->allocated_quantity)->toBe(10.0);
    });

    it('calculates remaining quantity', function () {
        $item = SupplierOrderItem::factory()->create([
            'quantity' => 50.0,
            'allocated_quantity' => 20.0,
        ]);

        expect((float) $item->getRemainingQuantity())->toBe(30.0);
    });

    it('can be marked as in supplies', function () {
        $item = SupplierOrderItem::factory()->inSupplies()->create();

        expect($item->isInSupplies())->toBeTrue()
            ->and($item->is_in_supplies)->toBe('Stock');
    });
});
