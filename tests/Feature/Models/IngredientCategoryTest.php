<?php

use App\Models\Supply\Ingredient;
use App\Models\Supply\IngredientCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('IngredientCategory Model', function () {
    it('can be created with factory', function () {
        $category = IngredientCategory::factory()->create();

        expect($category)
            ->toBeInstanceOf(IngredientCategory::class)
            ->and($category->name)->not->toBeEmpty();
    });

    it('can have a parent category', function () {
        $parent = IngredientCategory::factory()->create();
        $child = IngredientCategory::factory()->create(['parent_id' => $parent->id]);

        expect($child->parent->id)->toBe($parent->id);
    });

    it('has many ingredients', function () {
        $category = IngredientCategory::factory()->create();
        Ingredient::factory()->count(3)->create(['ingredient_category_id' => $category->id]);

        expect($category->ingredients)->toHaveCount(3);
    });

    it('can be an oils category', function () {
        $category = IngredientCategory::factory()->oils()->create();

        expect($category->name)->toBe('Huiles')
            ->and($category->code)->toBe('OIL');
    });

    it('can be an additives category', function () {
        $category = IngredientCategory::factory()->additives()->create();

        expect($category->name)->toBe('Additifs')
            ->and($category->code)->toBe('ADD');
    });
});
