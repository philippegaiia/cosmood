<?php

use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\ProductCategory;
use App\Models\Production\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Product Model', function () {
    it('can be created with factory', function () {
        $product = Product::factory()->create();

        expect($product)
            ->toBeInstanceOf(Product::class)
            ->and($product->name)->not->toBeEmpty();
    });

    it('belongs to a product category', function () {
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create(['product_category_id' => $category->id]);

        expect($product->productCategory->id)->toBe($category->id);
    });

    it('belongs to a product type', function () {
        $productType = ProductType::factory()->create();
        $product = Product::factory()->create(['product_type_id' => $productType->id]);

        expect($product->productType->id)->toBe($productType->id);
    });

    it('has many formulas', function () {
        $product = Product::factory()->create();
        Formula::factory()->count(2)->create(['product_id' => $product->id]);

        expect($product->formulas)->toHaveCount(2);
    });

    it('has many productions', function () {
        $product = Product::factory()->create();
        \App\Models\Production\Production::factory()->count(2)->create(['product_id' => $product->id]);

        expect($product->productions)->toHaveCount(2);
    });

    it('casts launch_date as date', function () {
        $product = Product::factory()->create();

        expect($product->launch_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    it('casts net_weight as decimal', function () {
        $product = Product::factory()->create(['net_weight' => 100.500]);

        expect((float) $product->net_weight)->toBe(100.500);
    });
});
