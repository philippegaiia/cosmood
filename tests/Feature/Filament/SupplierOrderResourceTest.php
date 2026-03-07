<?php

use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierOrder;
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
