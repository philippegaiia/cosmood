<?php

use App\Models\Supply\SupplierListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists supplier listings in table', function () {
    $listings = SupplierListing::factory()->count(3)->create();

    Livewire::test(\App\Filament\Resources\Supply\SupplierListingResource\Pages\ListSupplierListings::class)
        ->assertCanSeeTableRecords($listings);
});

it('searches supplier listings by name', function () {
    $listingA = SupplierListing::factory()->create(['name' => 'Beurre Karite Bio']);
    $listingB = SupplierListing::factory()->create(['name' => 'Huile Ricin']);

    Livewire::test(\App\Filament\Resources\Supply\SupplierListingResource\Pages\ListSupplierListings::class)
        ->searchTable('Karite')
        ->assertCanSeeTableRecords([$listingA])
        ->assertCanNotSeeTableRecords([$listingB]);
});
