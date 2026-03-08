<?php

use App\Enums\OrderStatus;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists supplier orders in table', function () {
    $orders = SupplierOrder::factory()->count(3)->create();

    Livewire::withQueryParams(['tab' => 'all'])
        ->test(\App\Filament\Resources\Supply\SupplierOrderResource\Pages\ListSupplierOrders::class)
        ->assertCanSeeTableRecords($orders);
});

it('searches supplier orders by reference', function () {
    $orderA = SupplierOrder::factory()->create(['order_ref' => 'PO-ALPHA-001']);
    $orderB = SupplierOrder::factory()->create(['order_ref' => 'PO-BETA-001']);

    Livewire::withQueryParams(['tab' => 'all'])
        ->test(\App\Filament\Resources\Supply\SupplierOrderResource\Pages\ListSupplierOrders::class)
        ->searchTable('ALPHA')
        ->assertCanSeeTableRecords([$orderA])
        ->assertCanNotSeeTableRecords([$orderB]);
});

it('prefills delivery date from supplier estimated delivery days', function () {
    $supplier = Supplier::factory()->create([
        'estimated_delivery_days' => 5,
        'code' => 'CAU',
    ]);

    Livewire::test(\App\Filament\Resources\Supply\SupplierOrderResource\Pages\CreateSupplierOrder::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'order_date' => '2026-03-07',
            'order_status' => \App\Enums\OrderStatus::Draft,
        ])
        ->assertSet('data.delivery_date', fn (string $value): bool => str_starts_with($value, '2026-03-12'));
});

it('uses default lead time of 8 days when supplier lead time is not customized', function () {
    $supplier = Supplier::factory()->create([
        'code' => 'DEF',
        'estimated_delivery_days' => 8,
    ]);

    Livewire::test(\App\Filament\Resources\Supply\SupplierOrderResource\Pages\CreateSupplierOrder::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'order_date' => '2026-03-07',
            'order_status' => \App\Enums\OrderStatus::Draft,
        ])
        ->assertSet('data.delivery_date', fn (string $value): bool => str_starts_with($value, '2026-03-15'));
});

it('saves edited order when adding a wave after item creation without engagement value', function () {
    $supplier = Supplier::factory()->create();
    $wave = ProductionWave::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'production_wave_id' => null,
        'order_status' => OrderStatus::Draft,
    ]);

    $item = SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'committed_quantity_kg' => 0,
    ]);

    Livewire::test(\App\Filament\Resources\Supply\SupplierOrderResource\Pages\EditSupplierOrder::class, ['record' => $order->id])
        ->fillForm([
            'production_wave_id' => $wave->id,
            'order_status' => OrderStatus::Passed,
            'supplier_order_items' => [[
                'id' => $item->id,
                'supplier_listing_id' => $item->supplier_listing_id,
                'quantity' => (float) $item->quantity,
                'unit_weight' => (float) $item->unit_weight,
                'unit_price' => (float) $item->unit_price,
                'batch_number' => $item->batch_number,
                'expiry_date' => optional($item->expiry_date)->format('Y-m-d'),
                'committed_quantity_kg' => null,
                'is_in_supplies' => $item->is_in_supplies,
            ]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $item->fresh()->committed_quantity_kg)->toBe(0.0)
        ->and($order->fresh()->production_wave_id)->toBe($wave->id);
});
