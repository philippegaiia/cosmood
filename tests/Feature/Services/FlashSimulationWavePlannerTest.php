<?php

use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Product;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductType;
use App\Models\Supply\Ingredient;
use App\Services\Production\FlashSimulationWavePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function flashSimulationWavePlanner(): FlashSimulationWavePlanner
{
    return app(FlashSimulationWavePlanner::class);
}

function createFlashPlannerFormula(Product $product, string $namePrefix): Formula
{
    $formula = Formula::query()->create([
        'name' => $namePrefix.' Formula',
        'slug' => Str::slug($namePrefix.'-'.Str::uuid()),
        'code' => 'FRM-'.Str::upper(Str::random(8)),
        'is_active' => true,
        'is_soap' => false,
        'date_of_creation' => now()->toDateString(),
    ]);

    $product->formulas()->syncWithoutDetaching([
        $formula->id => ['is_default' => true],
    ]);

    FormulaItem::factory()
        ->forFormula($formula)
        ->withIngredient(Ingredient::factory()->create([
            'name' => $namePrefix.' Oil',
            'price' => 8,
        ]))
        ->percentage(100)
        ->create();

    return $formula;
}

it('creates a persistent wave and batches planned by production line capacity', function (): void {
    $soapLine = ProductionLine::factory()->soapLine()->create([
        'daily_batch_capacity' => 2,
    ]);
    $deodorantLab = ProductionLine::factory()->deodorantLab()->create([
        'daily_batch_capacity' => 1,
    ]);

    $soapType = ProductType::factory()->withDefaultProductionLine($soapLine)->create([
        'default_batch_size' => 10,
        'expected_units_output' => 10,
    ]);
    $deoType = ProductType::factory()->withDefaultProductionLine($deodorantLab)->create([
        'default_batch_size' => 5,
        'expected_units_output' => 5,
    ]);

    $soapProduct = Product::factory()->withProductType($soapType)->create([
        'name' => 'Savon Test',
    ]);
    $deoProduct = Product::factory()->withProductType($deoType)->create([
        'name' => 'Deo Test',
    ]);

    createFlashPlannerFormula($soapProduct, 'Soap');
    createFlashPlannerFormula($deoProduct, 'Deo');

    $wave = flashSimulationWavePlanner()->createWaveFromSimulation(
        lines: [
            ['product_id' => $soapProduct->id, 'desired_units' => 25],
            ['product_id' => $deoProduct->id, 'desired_units' => 14],
        ],
        options: [
            'name' => 'Wave Plan Test',
            'start_date' => '2026-03-09',
            'skip_weekends' => true,
            'skip_holidays' => true,
            'fallback_daily_capacity' => 4,
        ],
    );

    $productions = $wave->productions()->orderBy('id')->get();

    $soapDates = $productions
        ->where('production_line_id', $soapLine->id)
        ->pluck('production_date')
        ->map(fn ($date) => $date?->toDateString())
        ->values()
        ->all();

    $deoDates = $productions
        ->where('production_line_id', $deodorantLab->id)
        ->pluck('production_date')
        ->map(fn ($date) => $date?->toDateString())
        ->values()
        ->all();

    expect($wave->fresh()->planned_start_date?->toDateString())->toBe('2026-03-09')
        ->and($wave->fresh()->planned_end_date?->toDateString())->toBe('2026-03-11')
        ->and($productions)->toHaveCount(6)
        ->and($soapDates)->toBe(['2026-03-09', '2026-03-09', '2026-03-10'])
        ->and($deoDates)->toBe(['2026-03-09', '2026-03-10', '2026-03-11'])
        ->and($productions->every(fn ($production): bool => $production->productionItems()->exists()))->toBeTrue();
});

it('uses fallback capacity when product type has no default production line', function (): void {
    $type = ProductType::factory()->create([
        'default_batch_size' => 2,
        'expected_units_output' => 10,
        'default_production_line_id' => null,
    ]);

    $product = Product::factory()->withProductType($type)->create();
    createFlashPlannerFormula($product, 'Fallback');

    $wave = flashSimulationWavePlanner()->createWaveFromSimulation(
        lines: [
            ['product_id' => $product->id, 'desired_units' => 25],
        ],
        options: [
            'name' => 'Fallback Wave',
            'start_date' => '2026-03-09',
            'skip_weekends' => true,
            'skip_holidays' => true,
            'fallback_daily_capacity' => 2,
        ],
    );

    $dates = $wave->productions()
        ->orderBy('id')
        ->pluck('production_date')
        ->map(fn ($date) => $date?->toDateString())
        ->values()
        ->all();

    expect($wave->productions()->whereNull('production_line_id')->count())->toBe(3)
        ->and($dates)->toBe(['2026-03-09', '2026-03-09', '2026-03-10']);
});
