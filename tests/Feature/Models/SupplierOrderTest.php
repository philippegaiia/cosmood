<?php

use App\Enums\OrderStatus;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

describe('SupplierOrder Model', function () {
    it('can be created with factory', function () {
        $order = SupplierOrder::factory()->create();

        expect($order)
            ->toBeInstanceOf(SupplierOrder::class)
            ->and($order->order_ref)->not->toBeEmpty();
    });

    it('belongs to a supplier', function () {
        $supplier = Supplier::factory()->create();
        $order = SupplierOrder::factory()->create(['supplier_id' => $supplier->id]);

        expect($order->supplier->id)->toBe($supplier->id);
    });

    it('can reference a production wave', function () {
        $wave = ProductionWave::factory()->create();
        $order = SupplierOrder::factory()->create([
            'production_wave_id' => $wave->id,
        ]);

        expect($order->wave?->id)->toBe($wave->id);
    });

    it('has many supplier order items', function () {
        $order = SupplierOrder::factory()->create();
        SupplierOrderItem::factory()->count(3)->create(['supplier_order_id' => $order->id]);

        expect($order->supplier_order_items)->toHaveCount(3);
    });

    it('casts order_status as enum', function () {
        $order = SupplierOrder::factory()->create(['order_status' => OrderStatus::Draft]);

        expect($order->order_status)->toBeInstanceOf(OrderStatus::class)
            ->and($order->order_status)->toBe(OrderStatus::Draft);
    });

    it('casts order and delivery dates to Carbon instances', function () {
        $order = SupplierOrder::factory()->create([
            'order_date' => '2026-03-01',
            'delivery_date' => '2026-03-08',
        ]);

        expect($order->order_date)->toBeInstanceOf(Carbon::class)
            ->and($order->delivery_date)->toBeInstanceOf(Carbon::class)
            ->and($order->delivery_date->toDateString())->toBe('2026-03-08');
    });

    it('can be marked as draft', function () {
        $order = SupplierOrder::factory()->draft()->create();

        expect($order->order_status)->toBe(OrderStatus::Draft);
    });

    it('can be marked as passed', function () {
        $order = SupplierOrder::factory()->passed()->create();

        expect($order->order_status)->toBe(OrderStatus::Passed);
    });

    it('can be marked as confirmed', function () {
        $order = SupplierOrder::factory()->confirmed()->create();

        expect($order->order_status)->toBe(OrderStatus::Confirmed);
    });

    it('can be marked as delivered', function () {
        $order = SupplierOrder::factory()->delivered()->create();

        expect($order->order_status)->toBe(OrderStatus::Delivered);
    });

    it('rejects transitions from checked to draft', function () {
        $order = SupplierOrder::factory()->create([
            'order_status' => OrderStatus::Checked,
        ]);

        expect(fn () => $order->update([
            'order_status' => OrderStatus::Draft,
        ]))->toThrow(InvalidArgumentException::class, 'Transition de statut invalide');
    });

    it('allows only forward transitions from draft', function () {
        expect(SupplierOrder::allowedTransitionsFor(OrderStatus::Draft))
            ->toBe([
                OrderStatus::Draft,
                OrderStatus::Passed,
                OrderStatus::Cancelled,
            ]);
    });

    it('rejects skipping directly from draft to checked', function () {
        $order = SupplierOrder::factory()->draft()->create();

        expect(fn () => $order->update([
            'order_status' => OrderStatus::Checked,
        ]))->toThrow(InvalidArgumentException::class, 'Transition de statut invalide');
    });

    it('exposes checked as a terminal status transition set', function () {
        $allowed = SupplierOrder::allowedTransitionsFor(OrderStatus::Checked);

        expect($allowed)->toHaveCount(1)
            ->and($allowed[0])->toBe(OrderStatus::Checked);
    });

    it('exposes cancelled as a terminal status transition set', function () {
        $allowed = SupplierOrder::allowedTransitionsFor(OrderStatus::Cancelled);

        expect($allowed)->toHaveCount(1)
            ->and($allowed[0])->toBe(OrderStatus::Cancelled);
    });

    it('updates ingredient last price when order is confirmed', function () {
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['price' => 5.50]);
        $listing = SupplierListing::factory()->create([
            'supplier_id' => $supplier->id,
            'ingredient_id' => $ingredient->id,
        ]);

        $order = SupplierOrder::factory()->passed()->create([
            'supplier_id' => $supplier->id,
        ]);

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $order->id,
            'supplier_listing_id' => $listing->id,
            'unit_price' => 12.40,
        ]);

        $order->update([
            'order_status' => OrderStatus::Confirmed,
        ]);

        expect((float) $ingredient->fresh()->price)->toBe(12.4);
    });

    it('auto-generates serial number and order reference on create when missing', function () {
        $supplier = Supplier::factory()->create([
            'code' => 'CAU',
        ]);

        $order = SupplierOrder::query()->create([
            'supplier_id' => $supplier->id,
            'order_status' => OrderStatus::Draft,
            'order_date' => now()->toDateString(),
        ]);

        expect($order->serial_number)->not->toBeNull()
            ->and($order->serial_number)->toBeInt()
            ->and($order->order_ref)->toContain('-CAU-');
    });

    it('resets committed quantities when wave link is removed', function () {
        $wave = ProductionWave::factory()->create();
        $supplier = Supplier::factory()->create();
        $listing = SupplierListing::factory()->create([
            'supplier_id' => $supplier->id,
        ]);

        $order = SupplierOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'production_wave_id' => $wave->id,
        ]);

        $item = SupplierOrderItem::factory()->create([
            'supplier_order_id' => $order->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 2,
            'unit_weight' => 10,
            'committed_quantity_kg' => 15,
        ]);

        $order->update([
            'production_wave_id' => null,
        ]);

        expect((float) $item->fresh()->committed_quantity_kg)->toBe(0.0);
    });

    it('deletes supplier orders permanently when they are empty', function () {
        $order = SupplierOrder::factory()->create();
        $orderId = $order->id;

        $order->delete();

        expect(SupplierOrder::query()->find($orderId))->toBeNull();
    });

    it('blocks deleting supplier orders that still contain items', function () {
        $order = SupplierOrder::factory()->create();

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $order->id,
        ]);

        expect(fn () => $order->delete())
            ->toThrow(InvalidArgumentException::class, 'Cette commande contient des ingrédients commandés.');

        expect(SupplierOrder::query()->find($order->id))->not->toBeNull();
    });
});
