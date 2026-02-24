<?php

use App\Enums\ProductionStatus;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SuppliesMovement;
use App\Models\Supply\Supply;
use App\Services\Production\ManufacturedIngredientStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates internal stock lot from finished manufactured production', function () {
    $ingredient = Ingredient::factory()->manufactured()->create([
        'name' => 'Macerat Curcuma',
        'code' => 'MCURCUMA',
        'price' => 11.5,
    ]);

    $production = Production::factory()->masterbatch()->finished()->create([
        'batch_number' => 'B-PLAN-1001',
        'permanent_batch_number' => '000321',
        'planned_quantity' => 26,
        'produced_ingredient_id' => $ingredient->id,
    ]);

    $supply = app(ManufacturedIngredientStockService::class)
        ->ensureStockFromFinishedProduction($production);

    expect($supply)->not->toBeNull()
        ->and($supply->source_production_id)->toBe($production->id)
        ->and($supply->batch_number)->toBe('000321')
        ->and((float) $supply->quantity_in)->toBe(26.0)
        ->and($supply->supplierListing)->not->toBeNull()
        ->and($supply->supplierListing->ingredient_id)->toBe($ingredient->id);

    expect(Supplier::query()->where('code', 'GAIIA-INT')->exists())->toBeTrue()
        ->and(
            SuppliesMovement::query()
                ->where('supply_id', $supply->id)
                ->where('movement_type', 'in')
                ->where('reason', 'Manufactured ingredient produced')
                ->exists()
        )->toBeTrue();
});

it('does not create duplicate stock lot for the same finished production', function () {
    $ingredient = Ingredient::factory()->manufactured()->create();

    $production = Production::factory()->finished()->create([
        'produced_ingredient_id' => $ingredient->id,
        'planned_quantity' => 15,
    ]);

    $service = app(ManufacturedIngredientStockService::class);

    $first = $service->ensureStockFromFinishedProduction($production);
    $second = $service->ensureStockFromFinishedProduction($production);

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($first->id)->toBe($second->id)
        ->and(Supply::query()->where('source_production_id', $production->id)->count())->toBe(1)
        ->and(SuppliesMovement::query()->where('supply_id', $first->id)->where('movement_type', 'in')->count())->toBe(1);
});

it('auto-creates internal stock lot when production status changes to finished', function () {
    $ingredient = Ingredient::factory()->manufactured()->create();

    $production = Production::factory()->confirmed()->create([
        'produced_ingredient_id' => $ingredient->id,
        'planned_quantity' => 10,
        'permanent_batch_number' => null,
    ]);

    $production->update([
        'status' => ProductionStatus::Finished,
    ]);

    $freshProduction = $production->fresh();

    $supply = Supply::query()
        ->where('source_production_id', $production->id)
        ->first();

    expect($supply)->not->toBeNull()
        ->and($freshProduction->permanent_batch_number)->not->toBeNull()
        ->and($supply->batch_number)->toBe($freshProduction->getLotIdentifier());
});

it('falls back to product manufactured ingredient when production target is empty', function () {
    $ingredient = Ingredient::factory()->manufactured()->create();
    $product = Product::factory()->create([
        'produced_ingredient_id' => $ingredient->id,
    ]);

    $production = Production::factory()->finished()->create([
        'product_id' => $product->id,
        'produced_ingredient_id' => null,
        'planned_quantity' => 8,
    ]);

    $supply = app(ManufacturedIngredientStockService::class)
        ->ensureStockFromFinishedProduction($production);

    expect($supply)->not->toBeNull()
        ->and($supply->supplierListing->ingredient_id)->toBe($ingredient->id)
        ->and($production->fresh()->produced_ingredient_id)->toBe($ingredient->id);
});
