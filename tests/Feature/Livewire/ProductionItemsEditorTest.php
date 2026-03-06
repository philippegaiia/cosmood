<?php

use App\Enums\AllocationStatus;
use App\Enums\FormulaItemCalculationMode;
use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Livewire\ProductionItemsEditor;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('merges a split child into its parent when deleting from dot menu', function (): void {
    $product = Product::factory()->create();
    $formula = Formula::query()->create([
        'name' => 'Formule delete split',
        'slug' => 'formule-delete-split',
        'code' => 'FRM-DEL-001',
        'is_active' => true,
    ]);

    $production = Production::query()->create([
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'slug' => 'batch-delete-split',
        'batch_number' => 'T90001',
        'status' => ProductionStatus::Planned,
        'production_date' => now()->toDateString(),
        'ready_date' => now()->addDay()->toDateString(),
        'organic' => true,
        'sizing_mode' => SizingMode::OilWeight,
        'planned_quantity' => 100,
        'expected_units' => 100,
    ]);

    $ingredient = Ingredient::factory()->create();

    $rootItem = ProductionItem::query()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'supplier_listing_id' => null,
        'percentage_of_oils' => 30,
        'phase' => Phases::Saponification,
        'calculation_mode' => FormulaItemCalculationMode::PercentOfOils,
        'required_quantity' => 30,
        'procurement_status' => ProcurementStatus::NotOrdered,
        'allocation_status' => AllocationStatus::Unassigned,
        'organic' => true,
        'is_supplied' => false,
        'sort' => 1,
    ]);

    $splitItem = ProductionItem::query()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'supplier_listing_id' => null,
        'percentage_of_oils' => 20,
        'phase' => Phases::Saponification,
        'calculation_mode' => FormulaItemCalculationMode::PercentOfOils,
        'required_quantity' => 20,
        'procurement_status' => ProcurementStatus::NotOrdered,
        'allocation_status' => AllocationStatus::Unassigned,
        'organic' => true,
        'is_supplied' => false,
        'sort' => 2,
        'split_from_item_id' => $rootItem->id,
        'split_root_item_id' => $rootItem->id,
    ]);

    Livewire::test(ProductionItemsEditor::class, ['productionId' => $production->id])
        ->call('removeItem', 1);

    expect(ProductionItem::find($splitItem->id))->toBeNull()
        ->and((float) $rootItem->fresh()->percentage_of_oils)->toBe(50.0)
        ->and((float) $rootItem->fresh()->required_quantity)->toBe(50.0);
});

it('prevents deleting a parent item that still has split children', function (): void {
    $product = Product::factory()->create();
    $formula = Formula::query()->create([
        'name' => 'Formule parent split',
        'slug' => 'formule-parent-split',
        'code' => 'FRM-DEL-002',
        'is_active' => true,
    ]);

    $production = Production::query()->create([
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'slug' => 'batch-parent-split',
        'batch_number' => 'T90002',
        'status' => ProductionStatus::Planned,
        'production_date' => now()->toDateString(),
        'ready_date' => now()->addDay()->toDateString(),
        'organic' => true,
        'sizing_mode' => SizingMode::OilWeight,
        'planned_quantity' => 100,
        'expected_units' => 100,
    ]);

    $ingredient = Ingredient::factory()->create();

    $rootItem = ProductionItem::query()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'supplier_listing_id' => null,
        'percentage_of_oils' => 30,
        'phase' => Phases::Saponification,
        'calculation_mode' => FormulaItemCalculationMode::PercentOfOils,
        'required_quantity' => 30,
        'procurement_status' => ProcurementStatus::NotOrdered,
        'allocation_status' => AllocationStatus::Unassigned,
        'organic' => true,
        'is_supplied' => false,
        'sort' => 1,
    ]);

    $splitItem = ProductionItem::query()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'supplier_listing_id' => null,
        'percentage_of_oils' => 20,
        'phase' => Phases::Saponification,
        'calculation_mode' => FormulaItemCalculationMode::PercentOfOils,
        'required_quantity' => 20,
        'procurement_status' => ProcurementStatus::NotOrdered,
        'allocation_status' => AllocationStatus::Unassigned,
        'organic' => true,
        'is_supplied' => false,
        'sort' => 2,
        'split_from_item_id' => $rootItem->id,
        'split_root_item_id' => $rootItem->id,
    ]);

    Livewire::test(ProductionItemsEditor::class, ['productionId' => $production->id])
        ->call('removeItem', 0);

    expect(ProductionItem::find($rootItem->id))->not->toBeNull()
        ->and(ProductionItem::find($splitItem->id))->not->toBeNull();
});

it('keeps ingredient and phase immutable when editing an existing item', function (): void {
    $product = Product::factory()->create();
    $formula = Formula::query()->create([
        'name' => 'Formule immutable item',
        'slug' => 'formule-immutable-item',
        'code' => 'FRM-DEL-003',
        'is_active' => true,
    ]);

    $production = Production::query()->create([
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'slug' => 'batch-immutable-item',
        'batch_number' => 'T90003',
        'status' => ProductionStatus::Planned,
        'production_date' => now()->toDateString(),
        'ready_date' => now()->addDay()->toDateString(),
        'organic' => true,
        'sizing_mode' => SizingMode::OilWeight,
        'planned_quantity' => 100,
        'expected_units' => 100,
    ]);

    $ingredientA = Ingredient::factory()->create();
    $ingredientB = Ingredient::factory()->create();

    $item = ProductionItem::query()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredientA->id,
        'supplier_listing_id' => null,
        'percentage_of_oils' => 30,
        'phase' => Phases::Saponification,
        'calculation_mode' => FormulaItemCalculationMode::PercentOfOils,
        'required_quantity' => 30,
        'procurement_status' => ProcurementStatus::NotOrdered,
        'allocation_status' => AllocationStatus::Unassigned,
        'organic' => true,
        'is_supplied' => false,
        'sort' => 1,
    ]);

    Livewire::test(ProductionItemsEditor::class, ['productionId' => $production->id])
        ->call('editItem', 0)
        ->set('editingItem.ingredient_id', $ingredientB->id)
        ->set('editingItem.phase', Phases::Packaging->value)
        ->set('editingItem.percentage_of_oils', 40)
        ->call('saveItem');

    expect($item->fresh()->ingredient_id)->toBe($ingredientA->id)
        ->and($item->fresh()->phase)->toBe(Phases::Saponification->value)
        ->and((float) $item->fresh()->percentage_of_oils)->toBe(40.0);
});

it('blocks deallocation of consumed allocations', function (): void {
    $product = Product::factory()->create();
    $formula = Formula::query()->create([
        'name' => 'Formule consumed dealloc',
        'slug' => 'formule-consumed-dealloc',
        'code' => 'FRM-DEL-004',
        'is_active' => true,
    ]);

    $production = Production::query()->create([
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'slug' => 'batch-consumed-dealloc',
        'batch_number' => 'T90004',
        'status' => ProductionStatus::Planned,
        'production_date' => now()->toDateString(),
        'ready_date' => now()->addDay()->toDateString(),
        'organic' => true,
        'sizing_mode' => SizingMode::OilWeight,
        'planned_quantity' => 100,
        'expected_units' => 100,
    ]);

    $ingredient = Ingredient::factory()->create();
    $supply = Supply::factory()->inStock(100)->create();

    $item = ProductionItem::query()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'supplier_listing_id' => null,
        'percentage_of_oils' => 30,
        'phase' => Phases::Saponification,
        'calculation_mode' => FormulaItemCalculationMode::PercentOfOils,
        'required_quantity' => 30,
        'procurement_status' => ProcurementStatus::NotOrdered,
        'allocation_status' => AllocationStatus::Allocated,
        'organic' => true,
        'is_supplied' => true,
        'sort' => 1,
        'supply_id' => $supply->id,
    ]);

    ProductionItemAllocation::query()->create([
        'production_item_id' => $item->id,
        'supply_id' => $supply->id,
        'quantity' => 30,
        'status' => 'consumed',
        'consumed_at' => now(),
    ]);

    Livewire::test(ProductionItemsEditor::class, ['productionId' => $production->id])
        ->call('deallocateItem', 0);

    expect($item->fresh()->supply_id)->toBe($supply->id)
        ->and($item->allocations()->where('status', 'consumed')->count())->toBe(1);
});

it('allows setting coefficient for a new unit-based item from modal flow', function (): void {
    $product = Product::factory()->create();
    $formula = Formula::query()->create([
        'name' => 'Formule add unit item',
        'slug' => 'formule-add-unit-item',
        'code' => 'FRM-DEL-005',
        'is_active' => true,
    ]);

    $production = Production::query()->create([
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'slug' => 'batch-add-unit-item',
        'batch_number' => 'T90005',
        'status' => ProductionStatus::Planned,
        'production_date' => now()->toDateString(),
        'ready_date' => now()->addDay()->toDateString(),
        'organic' => true,
        'sizing_mode' => SizingMode::OilWeight,
        'planned_quantity' => 100,
        'expected_units' => 55,
    ]);

    $unitIngredient = Ingredient::factory()->unitBased()->create();

    Livewire::test(ProductionItemsEditor::class, ['productionId' => $production->id])
        ->call('addItem')
        ->set('editingItem.ingredient_id', $unitIngredient->id)
        ->set('editingItem.percentage_of_oils', 2)
        ->call('saveItem');

    $savedItem = ProductionItem::query()->where('production_id', $production->id)->first();

    expect($savedItem)->not->toBeNull()
        ->and((float) $savedItem->percentage_of_oils)->toBe(2.0)
        ->and($savedItem->phase)->toBe(Phases::Saponification->value)
        ->and($savedItem->calculation_mode?->value)->toBe(FormulaItemCalculationMode::QuantityPerUnit->value)
        ->and((float) $savedItem->required_quantity)->toBe(110.0);
});

it('allows editing coefficient for a new kg-based item', function (): void {
    $product = Product::factory()->create();
    $formula = Formula::query()->create([
        'name' => 'Formule add oil item',
        'slug' => 'formule-add-oil-item',
        'code' => 'FRM-DEL-006',
        'is_active' => true,
    ]);

    $production = Production::query()->create([
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'slug' => 'batch-add-oil-item',
        'batch_number' => 'T90006',
        'status' => ProductionStatus::Planned,
        'production_date' => now()->toDateString(),
        'ready_date' => now()->addDay()->toDateString(),
        'organic' => true,
        'sizing_mode' => SizingMode::OilWeight,
        'planned_quantity' => 100,
        'expected_units' => 55,
    ]);

    $kgIngredient = Ingredient::factory()->create();

    Livewire::test(ProductionItemsEditor::class, ['productionId' => $production->id])
        ->call('addItem')
        ->set('editingItem.ingredient_id', $kgIngredient->id)
        ->set('editingItem.percentage_of_oils', 7.5)
        ->call('saveItem');

    $savedItem = ProductionItem::query()->where('production_id', $production->id)->first();

    expect($savedItem)->not->toBeNull()
        ->and((float) $savedItem->percentage_of_oils)->toBe(7.5)
        ->and($savedItem->calculation_mode?->value)->toBe(FormulaItemCalculationMode::PercentOfOils->value)
        ->and((float) $savedItem->required_quantity)->toBe(7.5);
});

it('allows manually marking an item as ordered', function (): void {
    $product = Product::factory()->create();
    $formula = Formula::query()->create([
        'name' => 'Formule mark ordered',
        'slug' => 'formule-mark-ordered',
        'code' => 'FRM-DEL-007',
        'is_active' => true,
    ]);

    $production = Production::query()->create([
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'slug' => 'batch-mark-ordered',
        'batch_number' => 'T90007',
        'status' => ProductionStatus::Planned,
        'production_date' => now()->toDateString(),
        'ready_date' => now()->addDay()->toDateString(),
        'organic' => true,
        'sizing_mode' => SizingMode::OilWeight,
        'planned_quantity' => 100,
        'expected_units' => 100,
    ]);

    $ingredient = Ingredient::factory()->create();

    $item = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'procurement_status' => ProcurementStatus::NotOrdered,
        'is_order_marked' => false,
    ]);

    Livewire::test(ProductionItemsEditor::class, ['productionId' => $production->id])
        ->call('markItemOrdered', 0);

    expect($item->fresh()->is_order_marked)->toBeTrue()
        ->and($item->fresh()->procurement_status)->toBe(ProcurementStatus::Ordered);
});

it('allows removing manual ordered mark on an item', function (): void {
    $product = Product::factory()->create();
    $formula = Formula::query()->create([
        'name' => 'Formule unmark ordered',
        'slug' => 'formule-unmark-ordered',
        'code' => 'FRM-DEL-008',
        'is_active' => true,
    ]);

    $production = Production::query()->create([
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'slug' => 'batch-unmark-ordered',
        'batch_number' => 'T90008',
        'status' => ProductionStatus::Planned,
        'production_date' => now()->toDateString(),
        'ready_date' => now()->addDay()->toDateString(),
        'organic' => true,
        'sizing_mode' => SizingMode::OilWeight,
        'planned_quantity' => 100,
        'expected_units' => 100,
    ]);

    $ingredient = Ingredient::factory()->create();

    $item = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'procurement_status' => ProcurementStatus::Ordered,
        'is_order_marked' => true,
    ]);

    Livewire::test(ProductionItemsEditor::class, ['productionId' => $production->id])
        ->call('unmarkItemOrdered', 0);

    expect($item->fresh()->is_order_marked)->toBeFalse()
        ->and($item->fresh()->procurement_status)->toBe(ProcurementStatus::NotOrdered);
});
