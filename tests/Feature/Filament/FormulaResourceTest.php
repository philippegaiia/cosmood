<?php

use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Product;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
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

    it('asks confirmation on save when saponified total is not 100 percent', function () {
        $soapProduct = Product::factory()->create();
        $formula = Formula::factory()->create([
            'product_id' => $soapProduct->id,
            'is_soap' => true,
        ]);

        FormulaItem::factory()->forFormula($formula)->percentage(40)->create();
        FormulaItem::factory()->forFormula($formula)->percentage(30)->create();

        Livewire::test(\App\Filament\Resources\Production\FormulaResource\Pages\EditFormula::class, ['record' => $formula->id])
            ->fillForm([
                'name' => 'Formula pending confirmation',
            ])
            ->call('save')
            ->assertNotified('Total saponifie different de 100%');

        expect($formula->fresh()->name)->not->toBe('Formula pending confirmation');

        Livewire::test(\App\Filament\Resources\Production\FormulaResource\Pages\EditFormula::class, ['record' => $formula->id])
            ->fillForm([
                'name' => 'Formula pending confirmation',
            ])
            ->call('save')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($formula->fresh()->name)->toBe('Formula pending confirmation');
    });

    it('does not ask confirmation when control is disabled, even with saponification lines', function () {
        $balmProduct = Product::factory()->create();
        $formula = Formula::factory()->create([
            'product_id' => $balmProduct->id,
            'is_soap' => false,
        ]);

        FormulaItem::factory()->forFormula($formula)->percentage(40)->create();
        FormulaItem::factory()->forFormula($formula)->percentage(30)->create();

        Livewire::test(\App\Filament\Resources\Production\FormulaResource\Pages\EditFormula::class, ['record' => $formula->id])
            ->fillForm([
                'name' => 'Formula no confirmation',
            ])
            ->call('save')
            ->assertNotNotified('Total saponifie different de 100%')
            ->assertHasNoFormErrors();

        expect($formula->fresh()->name)->toBe('Formula no confirmation');
    });

    it('asks confirmation when manual soap control is enabled on formula', function () {
        $formula = Formula::factory()->create([
            'is_soap' => true,
        ]);

        FormulaItem::factory()->forFormula($formula)->packaging()->percentage(1)->create();

        Livewire::test(\App\Filament\Resources\Production\FormulaResource\Pages\EditFormula::class, ['record' => $formula->id])
            ->fillForm([
                'name' => 'Formula manual control',
            ])
            ->call('save')
            ->assertNotified('Total saponifie different de 100%');

        expect($formula->fresh()->name)->not->toBe('Formula manual control');

        Livewire::test(\App\Filament\Resources\Production\FormulaResource\Pages\EditFormula::class, ['record' => $formula->id])
            ->fillForm([
                'name' => 'Formula manual control',
            ])
            ->call('save')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($formula->fresh()->name)->toBe('Formula manual control');
    });
});

describe('FormulaResource list table actions', function () {
    it('duplicates a formula with its items', function () {
        $formula = Formula::factory()->create([
            'name' => 'Savon Doux',
            'code' => 'FML-900001',
            'slug' => 'savon-doux',
            'is_soap' => true,
        ]);

        $firstItem = FormulaItem::factory()->forFormula($formula)->percentage(40)->create([
            'sort' => 1,
        ]);
        $secondItem = FormulaItem::factory()->forFormula($formula)->packaging()->percentage(1)->create([
            'sort' => 2,
        ]);

        Livewire::test(\App\Filament\Resources\Production\FormulaResource\Pages\ListFormulas::class)
            ->callAction(TestAction::make('duplicate')->table($formula))
            ->assertHasNoErrors();

        $duplicate = Formula::query()
            ->where('id', '!=', $formula->id)
            ->latest('id')
            ->first();

        expect($duplicate)->not->toBeNull()
            ->and($duplicate->name)->toBe('Savon Doux (copie)')
            ->and($duplicate->code)->toBeNull()
            ->and($duplicate->slug)->toBeNull()
            ->and($duplicate->is_soap)->toBeTrue();

        expect($duplicate->formulaItems()->count())->toBe(2);

        $copiedFirstItem = $duplicate->formulaItems()->orderBy('sort')->first();
        $copiedSecondItem = $duplicate->formulaItems()->orderByDesc('sort')->first();

        expect($copiedFirstItem?->ingredient_id)->toBe($firstItem->ingredient_id)
            ->and((float) $copiedFirstItem?->percentage_of_oils)->toBe(40.0)
            ->and($copiedSecondItem?->ingredient_id)->toBe($secondItem->ingredient_id)
            ->and((float) $copiedSecondItem?->percentage_of_oils)->toBe(1.0);
    });
});
