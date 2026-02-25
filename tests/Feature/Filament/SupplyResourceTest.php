<?php

use App\Models\Supply\Supply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists supplies in table', function () {
    $supplies = Supply::factory()->count(3)->create();

    Livewire::test(\App\Filament\Resources\Supply\SupplyResource\Pages\ListSupplies::class)
        ->assertCanSeeTableRecords($supplies);
});

it('loads supplies list page successfully', function () {
    $supplyA = Supply::factory()->create(['batch_number' => 'BATCH-ALPHA-01']);
    $supplyB = Supply::factory()->create(['batch_number' => 'BATCH-BETA-01']);

    Livewire::test(\App\Filament\Resources\Supply\SupplyResource\Pages\ListSupplies::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$supplyA, $supplyB]);
});

it('disables manual supply creation from inventory resource', function () {
    expect(\App\Filament\Resources\Supply\SupplyResource::canCreate())->toBeFalse();
});
