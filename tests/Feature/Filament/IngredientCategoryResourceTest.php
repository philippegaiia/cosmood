<?php

use App\Models\Supply\IngredientCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists ingredient categories in table', function () {
    $categories = IngredientCategory::factory()->count(3)->create();

    Livewire::test(\App\Filament\Resources\Supply\IngredientCategoryResource\Pages\ListIngredientCategories::class)
        ->assertCanSeeTableRecords($categories);
});

it('searches ingredient categories by name', function () {
    $categoryA = IngredientCategory::factory()->create(['name' => 'Huiles Vegetales']);
    $categoryB = IngredientCategory::factory()->create(['name' => 'Actifs']);

    Livewire::test(\App\Filament\Resources\Supply\IngredientCategoryResource\Pages\ListIngredientCategories::class)
        ->searchTable('Huiles')
        ->assertCanSeeTableRecords([$categoryA])
        ->assertCanNotSeeTableRecords([$categoryB]);
});
