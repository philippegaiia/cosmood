<?php

use App\Filament\Resources\Supply\SupplierListingResource\Pages\CreateSupplierListing;
use App\Filament\Resources\Supply\SupplierListingResource\Pages\ListSupplierListings;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    actingAs(User::factory()->create());
});

it('lists supplier listings in table', function () {
    $listings = SupplierListing::factory()->count(3)->create();

    Livewire::test(ListSupplierListings::class)
        ->assertCanSeeTableRecords($listings);
});

it('searches supplier listings by name', function () {
    $listingA = SupplierListing::factory()->create(['name' => 'Beurre Karite Bio']);
    $listingB = SupplierListing::factory()->create(['name' => 'Huile Ricin']);

    Livewire::test(ListSupplierListings::class)
        ->searchTable('Karite')
        ->assertCanSeeTableRecords([$listingA])
        ->assertCanNotSeeTableRecords([$listingB]);
});

it('defaults unit-based supplier listings to u and formats designation with parenthesized uom', function () {
    $supplier = Supplier::factory()->create();
    $ingredient = Ingredient::factory()->unitBased()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'name' => 'Boite tres doux',
        'unit_weight' => 1,
        'unit_of_measure' => 'u',
    ]);

    Livewire::test(CreateSupplierListing::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'ingredient_id' => $ingredient->id,
        ])
        ->assertSee('Contenu UOM')
        ->assertSee("Contenu d'une UOM fournisseur.")
        ->assertSet('data.unit_of_measure', 'u');

    Livewire::test(ListSupplierListings::class)
        ->assertCanSeeTableRecords([$listing])
        ->assertSee('Boite tres doux (1 u)');
});
