<?php

use App\Filament\Resources\Production\FormulaResource\Pages\ListFormulas;
use App\Filament\Resources\Production\ProductionLines\Pages\ListProductionLines;
use App\Filament\Resources\Production\ProductionTaskTypeResource\Pages\ListProductionTaskTypes;
use App\Filament\Resources\Production\ProductResource\Pages\ListProducts;
use App\Filament\Resources\Production\ProductTypes\Pages\ListProductTypes;
use App\Filament\Resources\Supply\IngredientCategoryResource\Pages\ListIngredientCategories;
use App\Filament\Resources\Supply\IngredientResource\Pages\ListIngredients;
use App\Filament\Resources\Supply\SupplierListingResource\Pages\ListSupplierListings;
use App\Filament\Resources\Supply\SupplierOrderResource\Pages\ListSupplierOrders;
use App\Filament\Resources\Supply\SupplyResource;
use App\Filament\Resources\Supply\SupplyResource\Pages\ListSupplies;
use App\Models\Production\Formula;
use App\Models\Production\ProductType;
use App\Models\Supply\Supply;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('keeps only plain delete bulk actions on simplified production resources', function () {
    Livewire::test(ListProductionTaskTypes::class)
        ->assertTableBulkActionExists('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete')
        ->assertTableBulkActionDoesNotExist('restore');

    Livewire::test(ListProductTypes::class)
        ->assertTableBulkActionExists('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete')
        ->assertTableBulkActionDoesNotExist('restore');

    Livewire::test(ListFormulas::class)
        ->assertTableBulkActionExists('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete')
        ->assertTableBulkActionDoesNotExist('restore');

    Livewire::test(ListProductionLines::class)
        ->assertTableBulkActionExists('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete')
        ->assertTableBulkActionDoesNotExist('restore');
});

it('removes bulk delete from guarded supply resources', function () {
    $supplierOrders = Livewire::test(ListSupplierOrders::class)->instance()->getTable();
    $supplierListings = Livewire::test(ListSupplierListings::class)->instance()->getTable();
    $ingredients = Livewire::test(ListIngredients::class)->instance()->getTable();
    $ingredientCategories = Livewire::test(ListIngredientCategories::class)->instance()->getTable();

    expect($supplierOrders->getBulkAction('delete'))->toBeNull()
        ->and($supplierOrders->getBulkAction('forceDelete'))->toBeNull()
        ->and($supplierOrders->getBulkAction('restore'))->toBeNull()
        ->and($supplierListings->getBulkAction('delete'))->toBeNull()
        ->and($supplierListings->getBulkAction('forceDelete'))->toBeNull()
        ->and($supplierListings->getBulkAction('restore'))->toBeNull()
        ->and($ingredients->getBulkAction('delete'))->toBeNull()
        ->and($ingredientCategories->getBulkAction('delete'))->toBeNull();
});

it('removes recovery-only bulk actions from products and stock lots', function () {
    $products = Livewire::test(ListProducts::class)->instance()->getTable();
    $supplies = Livewire::test(ListSupplies::class)->instance()->getTable();

    expect($products->getBulkAction('delete'))->toBeNull()
        ->and($products->getBulkAction('restore'))->toBeNull()
        ->and($supplies->getBulkAction('delete'))->toBeNull()
        ->and($supplies->getBulkAction('restore'))->toBeNull();
});

it('hides soft deleted product types from the default list query', function () {
    $activeProductType = ProductType::factory()->create();
    $deletedProductType = ProductType::factory()->create();
    $deletedProductType->delete();

    Livewire::test(ListProductTypes::class)
        ->assertCanSeeTableRecords([$activeProductType])
        ->assertCanNotSeeTableRecords([$deletedProductType]);
});

it('hides soft deleted formulas from the default list query', function () {
    $activeFormula = Formula::factory()->create();
    $deletedFormula = Formula::factory()->create();
    $deletedFormula->delete();

    Livewire::test(ListFormulas::class)
        ->assertCanSeeTableRecords([$activeFormula])
        ->assertCanNotSeeTableRecords([$deletedFormula]);
});

it('disables delete permissions for stock lots at the resource level', function () {
    $supply = Supply::factory()->create();

    expect(SupplyResource::canDelete($supply))->toBeFalse();
});
