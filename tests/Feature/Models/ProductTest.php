<?php

use App\Models\Production\Brand;
use App\Models\Production\Collection;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\ProductCategory;
use App\Models\Production\Production;
use App\Models\Production\ProductType;
use App\Models\Supply\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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

    it('can belong to a brand and collection', function () {
        $collection = Collection::factory()->create();
        $product = Product::factory()->create([
            'brand_id' => $collection->brand_id,
            'collection_id' => $collection->id,
        ]);

        expect($product->brand)->not->toBeNull()
            ->and($product->collection)->not->toBeNull()
            ->and($product->brand->id)->toBe($collection->brand_id)
            ->and($product->collection->id)->toBe($collection->id);
    });

    it('syncs the brand from the selected collection when missing', function () {
        $collection = Collection::factory()->create();

        $product = Product::factory()->create([
            'brand_id' => null,
            'collection_id' => $collection->id,
        ]);

        expect($product->brand_id)->toBe($collection->brand_id);
    });

    it('rejects a collection that belongs to another brand', function () {
        $brand = Brand::factory()->create();
        $otherCollection = Collection::factory()->create();

        expect(fn () => Product::factory()->create([
            'brand_id' => $brand->id,
            'collection_id' => $otherCollection->id,
        ]))->toThrow(InvalidArgumentException::class, 'La collection sélectionnée doit appartenir à la marque choisie.');
    });

    it('can belong to a manufactured ingredient output', function () {
        $ingredient = Ingredient::factory()->manufactured()->create();
        $product = Product::factory()->create(['produced_ingredient_id' => $ingredient->id]);

        expect($product->producedIngredient)->not->toBeNull()
            ->and($product->producedIngredient->id)->toBe($ingredient->id);
    });

    it('has many formulas via pivot', function () {
        $product = Product::factory()->create();
        $formulas = Formula::factory()->count(2)->create();

        foreach ($formulas as $formula) {
            $product->formulas()->attach($formula->id, ['is_default' => false]);
        }

        $product->setDefaultFormula($formulas->first()->id);

        expect($product->formulas)->toHaveCount(2)
            ->and($product->defaultFormula()->id)->toBe($formulas->first()->id);
    });

    it('has many productions', function () {
        $product = Product::factory()->create();
        Production::factory()->count(2)->create(['product_id' => $product->id]);

        expect($product->productions)->toHaveCount(2);
    });

    it('casts launch_date as date', function () {
        $product = Product::factory()->create();

        expect($product->launch_date)->toBeInstanceOf(Carbon::class);
    });

    it('casts net_weight as decimal', function () {
        $product = Product::factory()->create(['net_weight' => 100.500]);

        expect((float) $product->net_weight)->toBe(100.500);
    });
});
