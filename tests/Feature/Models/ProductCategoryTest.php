<?php

use App\Models\Production\Product;
use App\Models\Production\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ProductCategory Model', function () {
    it('can be created with factory', function () {
        $category = ProductCategory::factory()->create();

        expect($category)
            ->toBeInstanceOf(ProductCategory::class)
            ->and($category->name)->not->toBeEmpty();
    });

    it('has many products', function () {
        $category = ProductCategory::factory()->create();
        Product::factory()->count(3)->create(['product_category_id' => $category->id]);

        expect($category->products)->toHaveCount(3);
    });
});
