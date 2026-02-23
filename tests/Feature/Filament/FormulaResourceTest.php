<?php

use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('FormulaResource List', function () {
    it('can list formulas', function () {
        $formulas = Formula::factory()->count(3)->create();

        Livewire::test(\App\Filament\Resources\Production\FormulaResource\Pages\ListFormulas::class)
            ->assertCanSeeTableRecords($formulas);
    });

    it('can search formulas by name', function () {
        $formula1 = Formula::factory()->create(['name' => 'Formula Alpha']);
        $formula2 = Formula::factory()->create(['name' => 'Formula Beta']);

        Livewire::test(\App\Filament\Resources\Production\FormulaResource\Pages\ListFormulas::class)
            ->searchTable('Alpha')
            ->assertCanSeeTableRecords([$formula1])
            ->assertCanNotSeeTableRecords([$formula2]);
    });
});

describe('FormulaResource Create', function () {
    it('can create a formula', function () {
        $product = Product::factory()->create();

        Livewire::test(\App\Filament\Resources\Production\FormulaResource\Pages\CreateFormula::class)
            ->fillForm([
                'name' => 'New Formula',
                'code' => 'FML-001',
                'product_id' => $product->id,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Formula::class, [
            'name' => 'New Formula',
            'code' => 'FML-001',
        ]);
    });
});

describe('FormulaResource Edit', function () {
    it('can edit a formula', function () {
        $formula = Formula::factory()->create();

        Livewire::test(\App\Filament\Resources\Production\FormulaResource\Pages\EditFormula::class, ['record' => $formula->id])
            ->fillForm([
                'name' => 'Updated Formula',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($formula->fresh()->name)->toBe('Updated Formula');
    });
});
