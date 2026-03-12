<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\Supply\SupplierOrderResource\Pages\CreateSupplierOrder;
use App\Filament\Resources\Supply\SupplierOrderResource\Pages\EditSupplierOrder;
use App\Filament\Resources\Supply\SupplierOrderResource\Pages\ListSupplierOrders;
use App\Filament\Resources\Supply\SupplierOrderResource\RelationManagers\SupplierOrderItemsRelationManager;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists supplier orders in table', function () {
    $orders = SupplierOrder::factory()->count(3)->create();

    Livewire::withQueryParams(['tab' => 'all'])
        ->test(ListSupplierOrders::class)
        ->assertCanSeeTableRecords($orders);
});

it('searches supplier orders by reference', function () {
    $orderA = SupplierOrder::factory()->create(['order_ref' => 'PO-ALPHA-001']);
    $orderB = SupplierOrder::factory()->create(['order_ref' => 'PO-BETA-001']);

    Livewire::withQueryParams(['tab' => 'all'])
        ->test(ListSupplierOrders::class)
        ->searchTable('ALPHA')
        ->assertCanSeeTableRecords([$orderA])
        ->assertCanNotSeeTableRecords([$orderB]);
});

it('prefills delivery date from supplier estimated delivery days', function () {
    $supplier = Supplier::factory()->create([
        'estimated_delivery_days' => 5,
        'code' => 'CAU',
    ]);

    Livewire::test(CreateSupplierOrder::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'order_date' => '2026-03-07',
            'order_status' => OrderStatus::Draft,
        ])
        ->assertSet('data.delivery_date', fn (string $value): bool => str_starts_with($value, '2026-03-12'));
});

it('uses default lead time of 8 days when supplier lead time is not customized', function () {
    $supplier = Supplier::factory()->create([
        'code' => 'DEF',
        'estimated_delivery_days' => 8,
    ]);

    Livewire::test(CreateSupplierOrder::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'order_date' => '2026-03-07',
            'order_status' => OrderStatus::Draft,
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

    Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
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

    expect((float) $order->fresh()->supplier_order_items()->sole()->committed_quantity_kg)->toBe(0.0)
        ->and($order->fresh()->production_wave_id)->toBe($wave->id);
});

it('blocks negative order item quantities with form validation', function () {
    $supplier = Supplier::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'order_status' => OrderStatus::Draft,
    ]);

    $item = SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
    ]);

    Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
        ->fillForm([
            'supplier_order_items' => [[
                'id' => $item->id,
                'supplier_listing_id' => $item->supplier_listing_id,
                'quantity' => -3,
                'unit_weight' => (float) $item->unit_weight,
                'unit_price' => (float) $item->unit_price,
                'batch_number' => $item->batch_number,
                'expiry_date' => optional($item->expiry_date)->format('Y-m-d'),
                'committed_quantity_kg' => 0,
                'is_in_supplies' => $item->is_in_supplies,
            ]],
        ])
        ->call('save')
        ->assertHasFormErrors([
            'supplier_order_items.0.quantity' => 'min',
        ]);
});

it('keeps supplier order items relation manager on plain delete only', function () {
    $order = SupplierOrder::factory()->create();

    $table = Livewire::test(SupplierOrderItemsRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => EditSupplierOrder::class,
    ])->instance()->getTable();

    expect($table->getBulkAction('delete'))->toBeNull()
        ->and($table->getBulkAction('restore'))->toBeNull()
        ->and($table->getBulkAction('forceDelete'))->toBeNull();
});

it('hides deleted supplier order items from the default relation manager query', function () {
    $order = SupplierOrder::factory()->create();
    $visibleItem = SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
    ]);
    $deletedItem = SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
    ]);

    $deletedItem->delete();

    Livewire::test(SupplierOrderItemsRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => EditSupplierOrder::class,
    ])->assertCanSeeTableRecords([$visibleItem])
        ->assertCanNotSeeTableRecords([$deletedItem]);
});

it('blocks deleting supplier order items that are already in stock from the relation manager', function () {
    $listing = SupplierListing::factory()->create();
    $order = SupplierOrder::factory()->create([
        'supplier_id' => $listing->supplier_id,
    ]);
    $item = SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'is_in_supplies' => 'Stock',
        'moved_to_stock_at' => now(),
    ]);

    Supply::factory()->create([
        'supplier_listing_id' => $listing->id,
        'supplier_order_item_id' => $item->id,
    ]);

    Livewire::test(SupplierOrderItemsRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => EditSupplierOrder::class,
    ])
        ->callAction(TestAction::make('delete')->table($item))
        ->assertNotified();

    expect($item->fresh())->not->toBeNull();
});

it('shows a notification and keeps the order when deleting a non-empty supplier order', function () {
    $order = SupplierOrder::factory()->create();

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
    ]);

    Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
        ->callAction(DeleteAction::class)
        ->assertNotified();

    expect(SupplierOrder::query()->find($order->id))->not->toBeNull();
});
