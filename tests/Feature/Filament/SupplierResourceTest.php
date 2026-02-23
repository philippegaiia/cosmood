<?php

use App\Models\Supply\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists suppliers in table', function () {
    $suppliers = Supplier::factory()->count(3)->create();

    Livewire::test(\App\Filament\Resources\Supply\SupplierResource\Pages\ListSuppliers::class)
        ->assertCanSeeTableRecords($suppliers);
});

it('searches suppliers by name', function () {
    $supplierA = Supplier::factory()->create(['name' => 'Alpha Supply']);
    $supplierB = Supplier::factory()->create(['name' => 'Beta Trading']);

    Livewire::test(\App\Filament\Resources\Supply\SupplierResource\Pages\ListSuppliers::class)
        ->searchTable('Alpha')
        ->assertCanSeeTableRecords([$supplierA])
        ->assertCanNotSeeTableRecords([$supplierB]);
});
