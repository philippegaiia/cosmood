<?php

use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Product;
use App\Models\Production\ProductType;
use App\Models\Supply\Ingredient;
use App\Services\Production\FlashSimulationService;

function flashSimulationService(): FlashSimulationService
{
    return app(FlashSimulationService::class);
}

it('applies 1.15 multiplier for soap products', function () {
    $soapType = ProductType::factory()->soap()->create([
        'slug' => 'soap-bars',
        'name' => 'Soap Bars',
    ]);

    $product = Product::factory()->soap()->withProductType($soapType)->create([
        'net_weight' => 100,
    ]);

    $ingredient = Ingredient::factory()->create([
        'name' => 'Huile olive',
        'price' => 10,
    ]);

    $formula = Formula::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);

    FormulaItem::factory()->forFormula($formula)
        ->withIngredient($ingredient)
        ->percentage(100)
        ->create();

    $result = flashSimulationService()->simulate([
        ['product_id' => $product->id, 'units' => 10],
    ]);

    expect((float) $result['totals']['total_batch_kg'])->toBe(1.15)
        ->and((float) $result['ingredient_totals']->first()['required_kg'])->toBe(1.15)
        ->and((float) $result['ingredient_totals']->first()['estimated_cost'])->toBe(11.5);
});

it('aggregates ingredient needs for multiple selected products', function () {
    $type = ProductType::factory()->create([
        'slug' => 'balms',
        'name' => 'Balms',
    ]);

    $productA = Product::factory()->withProductType($type)->create([
        'net_weight' => 50,
    ]);
    $productB = Product::factory()->withProductType($type)->create([
        'net_weight' => 30,
    ]);

    $ingredient = Ingredient::factory()->create([
        'name' => 'Cire',
        'price' => 8,
    ]);

    $formulaA = Formula::factory()->create(['product_id' => $productA->id, 'is_active' => true]);
    $formulaB = Formula::factory()->create(['product_id' => $productB->id, 'is_active' => true]);

    FormulaItem::factory()->forFormula($formulaA)->withIngredient($ingredient)->percentage(100)->create();
    FormulaItem::factory()->forFormula($formulaB)->withIngredient($ingredient)->percentage(100)->create();

    $result = flashSimulationService()->simulate([
        ['product_id' => $productA->id, 'units' => 20],
        ['product_id' => $productB->id, 'units' => 10],
    ]);

    expect((float) $result['totals']['total_batch_kg'])->toBe(1.3)
        ->and((float) $result['ingredient_totals']->first()['required_kg'])->toBe(1.3)
        ->and((float) $result['totals']['total_estimated_cost'])->toBe(10.4);
});
