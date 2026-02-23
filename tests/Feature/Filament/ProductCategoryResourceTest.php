<?php

use App\Models\Production\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('ProductCategoryResource', function () {
    it('can list categories', function () {
        $categories = ProductCategory::factory()->count(3)->create();

        Livewire::test(\App\Filament\Resources\Production\ProductCategoryResource\Pages\ManageProductCategories::class)
            ->assertCanSeeTableRecords($categories);
    });
});
