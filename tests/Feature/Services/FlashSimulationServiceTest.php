<?php

use App\Enums\FormulaItemCalculationMode;
use App\Enums\IngredientBaseUnit;
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

it('uses product type fixed batch defaults for simulation', function () {
    $soapType = ProductType::factory()->soap()->create([
        'slug' => 'soap-bars',
        'name' => 'Soap Bars',
        'default_batch_size' => 1.15,
        'expected_units_output' => 10,
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
        ['product_id' => $product->id, 'desired_units' => 10],
    ]);

    expect((int) $result['totals']['total_batches'])->toBe(1)
        ->and((float) $result['totals']['total_batch_kg'])->toBe(1.15)
        ->and((float) $result['totals']['total_desired_units'])->toBe(10.0)
        ->and((float) $result['totals']['total_produced_units'])->toBe(10.0)
        ->and((float) $result['ingredient_totals']->first()['required_quantity'])->toBe(1.15)
        ->and((float) $result['ingredient_totals']->first()['estimated_cost'])->toBe(11.5);
});

it('aggregates ingredient needs for multiple selected products', function () {
    $typeA = ProductType::factory()->create([
        'slug' => 'soap-a',
        'name' => 'Soap A',
        'default_batch_size' => 1,
        'expected_units_output' => 20,
    ]);

    $typeB = ProductType::factory()->create([
        'slug' => 'soap-b',
        'name' => 'Soap B',
        'default_batch_size' => 0.3,
        'expected_units_output' => 10,
    ]);

    $productA = Product::factory()->withProductType($typeA)->create([
        'net_weight' => 50,
    ]);
    $productB = Product::factory()->withProductType($typeB)->create([
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
        ['product_id' => $productA->id, 'desired_units' => 20],
        ['product_id' => $productB->id, 'desired_units' => 10],
    ]);

    expect((int) $result['totals']['total_batches'])->toBe(2)
        ->and((float) $result['totals']['total_batch_kg'])->toBe(1.3)
        ->and((float) $result['totals']['total_produced_units'])->toBe(30.0)
        ->and((float) $result['ingredient_totals']->first()['required_quantity'])->toBe(1.3)
        ->and((float) $result['totals']['total_estimated_cost'])->toBe(10.4);
});

it('rounds requested quantity to full batches and exposes extra units', function () {
    $type = ProductType::factory()->create([
        'default_batch_size' => 26,
        'expected_units_output' => 288,
    ]);

    $product = Product::factory()->withProductType($type)->create();

    $ingredient = Ingredient::factory()->create([
        'price' => 1,
    ]);

    $formula = Formula::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);

    FormulaItem::factory()->forFormula($formula)->withIngredient($ingredient)->percentage(100)->create();

    $result = flashSimulationService()->simulate([
        ['product_id' => $product->id, 'desired_units' => 3200],
    ]);

    $line = $result['product_lines']->first();

    expect((int) $line['batches_required'])->toBe(12)
        ->and((float) $line['produced_units'])->toBe(3456.0)
        ->and((float) $line['extra_units'])->toBe(256.0)
        ->and((float) $line['oils_kg'])->toBe(312.0)
        ->and((int) $result['totals']['total_batches'])->toBe(12)
        ->and((float) $result['totals']['total_extra_units'])->toBe(256.0);
});

it('includes unit-based ingredients with quantity per unit calculation', function () {
    $type = ProductType::factory()->create([
        'default_batch_size' => 10,
        'expected_units_output' => 100,
    ]);

    $product = Product::factory()->withProductType($type)->create();

    $oilIngredient = Ingredient::factory()->create([
        'name' => 'Huile coco',
        'price' => 5,
        'base_unit' => IngredientBaseUnit::Kg->value,
    ]);

    $packagingIngredient = Ingredient::factory()->create([
        'name' => 'Bouteille',
        'price' => 0.5,
        'base_unit' => IngredientBaseUnit::Unit->value,
    ]);

    $formula = Formula::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);

    FormulaItem::factory()->forFormula($formula)
        ->withIngredient($oilIngredient)
        ->percentage(100)
        ->create();

    FormulaItem::factory()->forFormula($formula)
        ->withIngredient($packagingIngredient)
        ->state([
            'calculation_mode' => FormulaItemCalculationMode::QuantityPerUnit->value,
            'percentage_of_oils' => 1,
        ])
        ->create();

    $result = flashSimulationService()->simulate([
        ['product_id' => $product->id, 'desired_units' => 150],
    ]);

    $line = $result['product_lines']->first();

    expect((int) $line['batches_required'])->toBe(2)
        ->and((float) $line['produced_units'])->toBe(200.0)
        ->and($result['ingredient_totals'])->toHaveCount(2);

    $oilTotal = $result['ingredient_totals']->firstWhere('ingredient_name', 'Huile coco');
    $packagingTotal = $result['ingredient_totals']->firstWhere('ingredient_name', 'Bouteille');

    expect((float) $oilTotal['required_quantity'])->toBe(20.0)
        ->and($oilTotal['base_unit'])->toBe('kg')
        ->and((float) $oilTotal['estimated_cost'])->toBe(100.0)
        ->and((float) $packagingTotal['required_quantity'])->toBe(200.0)
        ->and($packagingTotal['base_unit'])->toBe('u')
        ->and((float) $packagingTotal['estimated_cost'])->toBe(100.0);
});
