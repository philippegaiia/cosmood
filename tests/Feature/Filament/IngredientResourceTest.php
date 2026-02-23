<?php

use App\Models\Supply\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists ingredients in table', function () {
    $ingredients = Ingredient::factory()->count(3)->create();

    Livewire::test(\App\Filament\Resources\Supply\IngredientResource\Pages\ListIngredients::class)
        ->assertCanSeeTableRecords($ingredients);
});

it('searches ingredients by name', function () {
    $ingredientA = Ingredient::factory()->create(['name' => 'Beurre de Karite']);
    $ingredientB = Ingredient::factory()->create(['name' => 'Huile de Coco']);

    Livewire::test(\App\Filament\Resources\Supply\IngredientResource\Pages\ListIngredients::class)
        ->searchTable('Karite')
        ->assertCanSeeTableRecords([$ingredientA])
        ->assertCanNotSeeTableRecords([$ingredientB]);
});
