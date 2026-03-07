<?php

use App\Enums\FormulaItemCalculationMode;
use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Production;
use App\Models\Supply\Ingredient;
use App\Services\Production\ProductionItemGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function productionItemGenerationService(): ProductionItemGenerationService
{
    return app(ProductionItemGenerationService::class);
}

function createProductionForItemGeneration(float $plannedQuantity, float $expectedUnits): Production
{
    $product = \App\Models\Production\Product::factory()->create();

    $formula = Formula::query()->create([
        'name' => 'Formula generation '.Str::uuid(),
        'slug' => Str::slug('formula-generation-'.Str::uuid()),
        'code' => 'FRM-'.Str::upper(Str::random(8)),
        'is_active' => true,
        'date_of_creation' => now()->toDateString(),
    ]);

    return Production::withoutEvents(function () use ($product, $formula, $plannedQuantity, $expectedUnits): Production {
        return Production::query()->create([
            'product_id' => $product->id,
            'formula_id' => $formula->id,
            'batch_number' => 'T'.str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT),
            'slug' => Str::slug('batch-generation-'.Str::uuid()),
            'status' => ProductionStatus::Planned,
            'sizing_mode' => SizingMode::OilWeight,
            'planned_quantity' => $plannedQuantity,
            'expected_units' => (int) $expectedUnits,
            'production_date' => now()->toDateString(),
            'ready_date' => now()->addDays(2)->toDateString(),
            'organic' => true,
        ]);
    });
}

it('stores required quantity for formula-based production items at generation time', function (): void {
    $production = createProductionForItemGeneration(plannedQuantity: 20, expectedUnits: 100);
    $ingredient = Ingredient::factory()->create();

    FormulaItem::factory()->create([
        'formula_id' => $production->formula_id,
        'ingredient_id' => $ingredient->id,
        'percentage_of_oils' => 25,
        'phase' => Phases::Saponification->value,
        'calculation_mode' => FormulaItemCalculationMode::PercentOfOils->value,
    ]);

    productionItemGenerationService()->generateFromFormula($production->fresh());

    $item = $production->fresh()->productionItems()->where('ingredient_id', $ingredient->id)->first();

    expect($item)->not->toBeNull()
        ->and((float) $item->required_quantity)->toBe(5.0);
});

it('stores required quantity for packaging items using expected units', function (): void {
    $production = createProductionForItemGeneration(plannedQuantity: 12, expectedUnits: 150);

    $packagingIngredient = Ingredient::factory()->unitBased()->create([
        'is_packaging' => true,
        'name' => 'Flacon 100ml',
    ]);

    $production->product->packaging()->attach($packagingIngredient->id, [
        'quantity_per_unit' => 2,
        'sort' => 1,
    ]);

    productionItemGenerationService()->generateFromFormula($production->fresh());

    $item = $production->fresh()->productionItems()->where('ingredient_id', $packagingIngredient->id)->first();

    expect($item)->not->toBeNull()
        ->and((string) $item->phase)->toBe(Phases::Packaging->value)
        ->and($item->calculation_mode)->toBe(FormulaItemCalculationMode::QuantityPerUnit)
        ->and((float) $item->required_quantity)->toBe(300.0);
});
