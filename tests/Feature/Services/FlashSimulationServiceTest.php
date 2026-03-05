<?php

use App\Enums\FormulaItemCalculationMode;
use App\Enums\IngredientBaseUnit;
use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Product;
use App\Models\Production\ProductType;
use App\Models\Production\TaskTemplate;
use App\Models\Production\TaskTemplateItem;
use App\Models\Supply\Ingredient;
use App\Services\Production\FlashSimulationService;
use Illuminate\Support\Str;

function flashSimulationService(): FlashSimulationService
{
    return app(FlashSimulationService::class);
}

function createActiveFormulaForProduct(Product $product): Formula
{
    $formula = Formula::query()->create([
        'name' => fake()->words(2, true),
        'slug' => Str::slug(fake()->words(2, true).'-'.Str::lower((string) Str::uuid())),
        'code' => 'FRM-'.Str::upper(Str::random(8)),
        'is_active' => true,
        'is_soap' => false,
        'date_of_creation' => now()->toDateString(),
        'description' => null,
        'dip_number' => null,
        'replaces_phase' => null,
    ]);

    $product->formulas()->syncWithoutDetaching([
        $formula->id => ['is_default' => true],
    ]);

    return $formula;
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

    $formula = createActiveFormulaForProduct($product);

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

    $formulaA = createActiveFormulaForProduct($productA);
    $formulaB = createActiveFormulaForProduct($productB);

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

    $formula = createActiveFormulaForProduct($product);

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

    $formula = createActiveFormulaForProduct($product);

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

it('includes product packaging requirements in ingredient totals', function () {
    $type = ProductType::factory()->create([
        'default_batch_size' => 10,
        'expected_units_output' => 100,
    ]);

    $product = Product::factory()->withProductType($type)->create();

    $oilIngredient = Ingredient::factory()->create([
        'name' => 'Huile olive',
        'price' => 5,
        'base_unit' => IngredientBaseUnit::Kg->value,
    ]);

    $packagingIngredient = Ingredient::factory()->create([
        'name' => 'Etui carton',
        'price' => 0.3,
        'base_unit' => IngredientBaseUnit::Unit->value,
        'is_packaging' => true,
    ]);

    $product->packaging()->attach($packagingIngredient->id, [
        'quantity_per_unit' => 1,
        'sort' => 1,
    ]);

    $formula = createActiveFormulaForProduct($product);

    FormulaItem::factory()->forFormula($formula)
        ->withIngredient($oilIngredient)
        ->percentage(100)
        ->create();

    $result = flashSimulationService()->simulate([
        ['product_id' => $product->id, 'desired_units' => 150],
    ]);

    $line = $result['product_lines']->first();
    $oilTotal = $result['ingredient_totals']->firstWhere('ingredient_name', 'Huile olive');
    $packagingTotal = $result['ingredient_totals']->firstWhere('ingredient_name', 'Etui carton');

    expect((int) $line['batches_required'])->toBe(2)
        ->and((float) $line['produced_units'])->toBe(200.0)
        ->and((float) $oilTotal['required_quantity'])->toBe(20.0)
        ->and((float) $packagingTotal['required_quantity'])->toBe(200.0)
        ->and($packagingTotal['base_unit'])->toBe('u')
        ->and((float) $packagingTotal['estimated_cost'])->toBe(60.0)
        ->and((float) $result['totals']['total_estimated_cost'])->toBe(160.0);
});

it('consolidates task durations globally per task name', function () {
    $type = ProductType::factory()->create([
        'default_batch_size' => 10,
        'expected_units_output' => 100,
    ]);

    $taskTemplate = TaskTemplate::query()->create([
        'name' => 'Template Global',
        'product_category_id' => $type->product_category_id,
    ]);

    $type->taskTemplates()->attach($taskTemplate->id, ['is_default' => true]);

    TaskTemplateItem::query()->create([
        'task_template_id' => $taskTemplate->id,
        'name' => 'Melange',
        'duration_hours' => 0,
        'duration_minutes' => 30,
        'offset_days' => 0,
        'skip_weekends' => true,
        'sort_order' => 1,
    ]);

    TaskTemplateItem::query()->create([
        'task_template_id' => $taskTemplate->id,
        'name' => 'Moulage',
        'duration_hours' => 0,
        'duration_minutes' => 20,
        'offset_days' => 1,
        'skip_weekends' => true,
        'sort_order' => 2,
    ]);

    $ingredient = Ingredient::factory()->create([
        'price' => 2,
    ]);

    $productA = Product::factory()->withProductType($type)->create();
    $formulaA = createActiveFormulaForProduct($productA);
    FormulaItem::factory()->forFormula($formulaA)->withIngredient($ingredient)->percentage(100)->create();

    $productB = Product::factory()->withProductType($type)->create();
    $formulaB = createActiveFormulaForProduct($productB);
    FormulaItem::factory()->forFormula($formulaB)->withIngredient($ingredient)->percentage(100)->create();

    $result = flashSimulationService()->simulate([
        ['product_id' => $productA->id, 'desired_units' => 150],
        ['product_id' => $productB->id, 'desired_units' => 220],
    ]);

    $melange = $result['task_totals']->firstWhere('name', 'Melange');
    $moulage = $result['task_totals']->firstWhere('name', 'Moulage');

    expect((float) $result['totals']['total_duration_minutes'])->toBe(250.0)
        ->and((float) $melange['average_duration_per_batch_minutes'])->toBe(30.0)
        ->and((float) $moulage['average_duration_per_batch_minutes'])->toBe(20.0)
        ->and((float) $melange['total_duration_minutes'])->toBe(150.0)
        ->and((float) $moulage['total_duration_minutes'])->toBe(100.0)
        ->and((float) $melange['batches'])->toBe(5.0)
        ->and((float) $moulage['batches'])->toBe(5.0);
});

it('computes weighted average duration per batch in consolidated tasks', function () {
    $typeA = ProductType::factory()->create([
        'default_batch_size' => 10,
        'expected_units_output' => 100,
    ]);

    $typeB = ProductType::factory()->create([
        'default_batch_size' => 10,
        'expected_units_output' => 100,
    ]);

    $templateA = TaskTemplate::query()->create([
        'name' => 'Template A',
        'product_category_id' => $typeA->product_category_id,
    ]);

    $templateB = TaskTemplate::query()->create([
        'name' => 'Template B',
        'product_category_id' => $typeB->product_category_id,
    ]);

    $typeA->taskTemplates()->attach($templateA->id, ['is_default' => true]);
    $typeB->taskTemplates()->attach($templateB->id, ['is_default' => true]);

    TaskTemplateItem::query()->create([
        'task_template_id' => $templateA->id,
        'name' => 'Conditionnement',
        'duration_hours' => 0,
        'duration_minutes' => 30,
        'offset_days' => 0,
        'skip_weekends' => true,
        'sort_order' => 1,
    ]);

    TaskTemplateItem::query()->create([
        'task_template_id' => $templateB->id,
        'name' => 'Conditionnement',
        'duration_hours' => 0,
        'duration_minutes' => 60,
        'offset_days' => 0,
        'skip_weekends' => true,
        'sort_order' => 1,
    ]);

    $ingredient = Ingredient::factory()->create(['price' => 1]);

    $productA = Product::factory()->withProductType($typeA)->create();
    $formulaA = createActiveFormulaForProduct($productA);
    FormulaItem::factory()->forFormula($formulaA)->withIngredient($ingredient)->percentage(100)->create();

    $productB = Product::factory()->withProductType($typeB)->create();
    $formulaB = createActiveFormulaForProduct($productB);
    FormulaItem::factory()->forFormula($formulaB)->withIngredient($ingredient)->percentage(100)->create();

    $result = flashSimulationService()->simulate([
        ['product_id' => $productA->id, 'desired_units' => 200], // 2 batches
        ['product_id' => $productB->id, 'desired_units' => 100], // 1 batch
    ]);

    $task = $result['task_totals']->firstWhere('name', 'Conditionnement');

    expect((float) $task['batches'])->toBe(3.0)
        ->and((float) $task['total_duration_minutes'])->toBe(120.0)
        ->and((float) $task['average_duration_per_batch_minutes'])->toBe(40.0)
        ->and((float) $task['duration_per_batch_minutes'])->toBe(40.0);
});
