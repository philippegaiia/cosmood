<?php

use App\Livewire\SimulateurFlashPage;
use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Product;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use App\Models\Supply\Ingredient;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

function createComponentFlashFormula(Product $product): Formula
{
    $formula = Formula::query()->create([
        'name' => 'Formule composant flash',
        'slug' => Str::slug('formule-composant-'.Str::uuid()),
        'code' => 'FRM-'.Str::upper(Str::random(8)),
        'is_active' => true,
        'date_of_creation' => now()->toDateString(),
    ]);

    $product->formulas()->syncWithoutDetaching([
        $formula->id => ['is_default' => true],
    ]);

    FormulaItem::factory()
        ->forFormula($formula)
        ->withIngredient(Ingredient::factory()->create())
        ->percentage(100)
        ->create();

    return $formula;
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the livewire simulateur flash component', function () {
    Livewire::test(SimulateurFlashPage::class)
        ->assertSuccessful()
        ->assertSee('Simulateur Flash')
        ->assertSee('Selectionner un produit')
        ->assertSee('Synthese')
        ->assertSee('Print');
});

it('shows products in selection even when they are inactive in legacy data', function () {
    Product::factory()->create([
        'name' => 'Savon Legacy Inactif',
        'is_active' => 0,
    ]);

    Livewire::test(SimulateurFlashPage::class)
        ->assertSee('Savon Legacy Inactif');
});

it('creates a persistent wave from the simulator component', function () {
    $line = ProductionLine::factory()->soapLine()->create([
        'daily_batch_capacity' => 2,
    ]);

    $type = ProductType::factory()->withDefaultProductionLine($line)->create([
        'default_batch_size' => 10,
        'expected_units_output' => 10,
    ]);

    $product = Product::factory()->withProductType($type)->create([
        'name' => 'Savon composant flash',
    ]);

    createComponentFlashFormula($product);

    Livewire::test(SimulateurFlashPage::class)
        ->set('lines.0.product_id', $product->id)
        ->set('lines.0.desired_units', 15)
        ->set('waveName', 'Wave depuis composant')
        ->set('waveStartDate', '2026-03-09')
        ->set('plannerFallbackDailyCapacity', 4)
        ->call('recalculate')
        ->call('createWaveFromSimulation');

    $wave = ProductionWave::query()->where('name', 'Wave depuis composant')->first();

    expect($wave)->not->toBeNull()
        ->and($wave->productions()->count())->toBe(2)
        ->and($wave->productions()->where('production_line_id', $line->id)->count())->toBe(2);
});
