<?php

use App\Filament\Resources\Production\Brands\Pages\ManageBrands;
use App\Filament\Resources\Production\Collections\Pages\ManageCollections;
use App\Filament\Resources\Production\Destinations\Pages\ManageDestinations;
use App\Models\Production\Brand;
use App\Models\Production\Collection;
use App\Models\Production\Destination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('lists brands in the manage brands page', function () {
    $brands = Brand::factory()->count(3)->create();

    Livewire::test(ManageBrands::class)
        ->assertCanSeeTableRecords($brands);
});

it('lists collections in the manage collections page', function () {
    $collections = Collection::factory()->count(3)->create();

    Livewire::test(ManageCollections::class)
        ->assertCanSeeTableRecords($collections);
});

it('lists destinations in the manage destinations page', function () {
    $destinations = Destination::factory()->count(3)->create();

    Livewire::test(ManageDestinations::class)
        ->assertCanSeeTableRecords($destinations);
});
