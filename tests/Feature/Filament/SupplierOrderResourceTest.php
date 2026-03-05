<?php

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
