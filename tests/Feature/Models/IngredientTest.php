<?php

use App\Models\Supply\Ingredient;
use App\Models\Supply\IngredientCategory;
use App\Models\Supply\SupplierListing;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Ingredient Model', function () {
    it('can be created with factory', function () {
        $ingredient = Ingredient::factory()->create();

        expect($ingredient)
            ->toBeInstanceOf(Ingredient::class)
            ->and($ingredient->name)->not->toBeEmpty();
    });

    it('belongs to an ingredient category', function () {
        $category = IngredientCategory::factory()->create();
        $ingredient = Ingredient::factory()->create(['ingredient_category_id' => $category->id]);

        expect($ingredient->ingredient_category->id)->toBe($category->id);
    });

    it('has many supplier listings', function () {
        $ingredient = Ingredient::factory()->create();
        SupplierListing::factory()->count(2)->create(['ingredient_id' => $ingredient->id]);

        expect($ingredient->supplier_listings)->toHaveCount(2);
    });

    it('can be marked as inactive', function () {
        $ingredient = Ingredient::factory()->inactive()->create();

        expect($ingredient->is_active)->toBeFalse();
    });

    it('can be an oil ingredient', function () {
        $ingredient = Ingredient::factory()->oil()->create();

        expect($ingredient->name)->toBe('Huile de Coco')
            ->and($ingredient->inci)->toBe('Cocos Nucifera Oil');
    });
});
