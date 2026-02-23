<?php

use App\Models\Production\Product;
use App\Models\Production\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('ProductResource List', function () {
    it('can list products', function () {
        $products = Product::factory()->count(3)->create();

        Livewire::test(\App\Filament\Resources\Production\ProductResource\Pages\ListProducts::class)
            ->assertCanSeeTableRecords($products);
    });

    it('can search products by name', function () {
        $product1 = Product::factory()->create(['name' => 'Savon Coco']);
        $product2 = Product::factory()->create(['name' => 'Baume Menthe']);

        Livewire::test(\App\Filament\Resources\Production\ProductResource\Pages\ListProducts::class)
            ->searchTable('Coco')
            ->assertCanSeeTableRecords([$product1])
            ->assertCanNotSeeTableRecords([$product2]);
    });
});

describe('ProductResource Create', function () {
    it('can create a product', function () {
        $category = ProductCategory::factory()->create();

        Livewire::test(\App\Filament\Resources\Production\ProductResource\Pages\CreateProduct::class)
            ->fillForm([
                'name' => 'New Product',
                'code' => 'PROD-001',
                'product_category_id' => $category->id,
                'launch_date' => now()->format('Y-m-d'),
                'net_weight' => 100,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Product::class, [
            'name' => 'New Product',
            'code' => 'PROD-001',
        ]);
    });
});

describe('ProductResource Edit', function () {
    it('can edit a product', function () {
        $product = Product::factory()->create();

        Livewire::test(\App\Filament\Resources\Production\ProductResource\Pages\EditProduct::class, ['record' => $product->id])
            ->fillForm([
                'name' => 'Updated Product',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($product->fresh()->name)->toBe('Updated Product');
    });
});
