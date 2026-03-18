<?php

use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createConfirmedProductionForOngoingGuard(): Production
{
    $product = Product::factory()->create();

    $formula = Formula::query()->create([
        'name' => 'Formula ongoing guard',
        'slug' => Str::slug('formula-ongoing-guard-'.Str::uuid()),
        'code' => 'FRM-'.Str::upper(Str::random(8)),
        'is_active' => true,
        'is_soap' => false,
        'date_of_creation' => now()->toDateString(),
    ]);

    return Production::withoutEvents(function () use ($product, $formula): Production {
        return Production::query()->create([
            'product_id' => $product->id,
            'formula_id' => $formula->id,
            'batch_number' => 'T'.str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT),
            'slug' => Str::slug('batch-ongoing-guard-'.Str::uuid()),
            'status' => ProductionStatus::Confirmed,
            'sizing_mode' => SizingMode::OilWeight,
            'planned_quantity' => 10,
            'expected_units' => 100,
            'production_date' => now()->toDateString(),
            'ready_date' => now()->addDays(2)->toDateString(),
            'organic' => true,
        ]);
    });
}

it('blocks transition to ongoing when any production item is not allocated', function (): void {
    $production = createConfirmedProductionForOngoingGuard();
    $ingredient = Ingredient::factory()->create();

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 7,
        'supply_id' => null,
    ]);

    expect(fn () => $production->update(['status' => ProductionStatus::Ongoing]))
        ->toThrow(InvalidArgumentException::class, 'Cannot set production to ongoing: unallocated items');
});

it('allows transition to ongoing when all production items are fully allocated', function (): void {
    $production = createConfirmedProductionForOngoingGuard();
    $ingredient = Ingredient::factory()->create();
    $supply = Supply::factory()->inStock(50)->create();

    $item = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 7,
        'supply_id' => $supply->id,
    ]);

    ProductionItemAllocation::query()->create([
        'production_item_id' => $item->id,
        'supply_id' => $supply->id,
        'quantity' => 7,
        'status' => 'reserved',
        'reserved_at' => now(),
    ]);

    $production->update(['status' => ProductionStatus::Ongoing]);

    expect($production->fresh()->status)->toBe(ProductionStatus::Ongoing);
});

it('allows transition to ongoing when only packaging items are still unallocated', function (): void {
    $production = createConfirmedProductionForOngoingGuard();
    $fabricationIngredient = Ingredient::factory()->create();
    $packagingIngredient = Ingredient::factory()->create();
    $supply = Supply::factory()->inStock(50)->create();

    $allocatedItem = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $fabricationIngredient->id,
        'required_quantity' => 7,
        'phase' => Phases::Additives->value,
        'supply_id' => $supply->id,
    ]);

    ProductionItemAllocation::query()->create([
        'production_item_id' => $allocatedItem->id,
        'supply_id' => $supply->id,
        'quantity' => 7,
        'status' => 'reserved',
        'reserved_at' => now(),
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $packagingIngredient->id,
        'required_quantity' => 120,
        'phase' => Phases::Packaging->value,
        'supply_id' => null,
    ]);

    expect($production->getUnallocatedIngredientNamesForOngoing())->toBe([])
        ->and($production->getUnallocatedPackagingIngredientNamesForOngoing())->toBe([$packagingIngredient->name]);

    $production->update(['status' => ProductionStatus::Ongoing]);

    expect($production->fresh()->status)->toBe(ProductionStatus::Ongoing);
});
