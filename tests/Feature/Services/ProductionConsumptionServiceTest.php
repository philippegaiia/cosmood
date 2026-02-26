<?php

use App\Enums\FormulaItemCalculationMode;
use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SuppliesMovement;
use App\Models\Supply\Supply;
use App\Services\Production\ProductionConsumptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('consumes non-packaging supplies when production starts and stays idempotent', function () {
    $supplyA = Supply::factory()->inStock(50)->create();
    $supplyB = Supply::factory()->inStock(50)->create();

    $production = Production::factory()->confirmed()->create([
        'planned_quantity' => 20,
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'supply_id' => $supplyA->id,
        'supplier_listing_id' => $supplyA->supplier_listing_id,
        'ingredient_id' => $supplyA->supplierListing->ingredient_id,
        'phase' => Phases::Saponification->value,
        'percentage_of_oils' => 10,
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'supply_id' => $supplyB->id,
        'supplier_listing_id' => $supplyB->supplier_listing_id,
        'ingredient_id' => $supplyB->supplierListing->ingredient_id,
        'phase' => Phases::Additives->value,
        'percentage_of_oils' => 5,
    ]);

    $production->update(['status' => ProductionStatus::Ongoing]);

    expect((float) $supplyA->fresh()->quantity_out)->toBe(2.0)
        ->and((float) $supplyB->fresh()->quantity_out)->toBe(1.0)
        ->and(
            SuppliesMovement::query()
                ->where('production_id', $production->id)
                ->where('movement_type', 'out')
                ->where('reason', ProductionConsumptionService::REASON_ONGOING_CONSUMPTION)
                ->count()
        )->toBe(2);

    app(ProductionConsumptionService::class)->consumeForOngoingProduction($production->fresh());

    expect((float) $supplyA->fresh()->quantity_out)->toBe(2.0)
        ->and((float) $supplyB->fresh()->quantity_out)->toBe(1.0)
        ->and(
            SuppliesMovement::query()
                ->where('production_id', $production->id)
                ->where('movement_type', 'out')
                ->where('reason', ProductionConsumptionService::REASON_ONGOING_CONSUMPTION)
                ->count()
        )->toBe(2);
});

it('consumes masterbatch lot and avoids double counting replaced phase ingredients', function () {
    $masterbatchIngredient = Ingredient::factory()->manufactured()->create();
    $masterbatchListing = SupplierListing::factory()->create([
        'ingredient_id' => $masterbatchIngredient->id,
    ]);

    $masterbatch = Production::factory()->masterbatch()->finished()->create([
        'planned_quantity' => 26,
    ]);

    $masterbatchSupply = Supply::factory()->inStock(100)->create([
        'supplier_listing_id' => $masterbatchListing->id,
        'source_production_id' => $masterbatch->id,
    ]);

    $rawSupply = Supply::factory()->inStock(100)->create();
    $additiveSupply = Supply::factory()->inStock(100)->create();

    $production = Production::factory()->confirmed()->create([
        'planned_quantity' => 20,
        'masterbatch_lot_id' => $masterbatch->id,
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'supply_id' => $rawSupply->id,
        'supplier_listing_id' => $rawSupply->supplier_listing_id,
        'ingredient_id' => $rawSupply->supplierListing->ingredient_id,
        'phase' => Phases::Saponification->value,
        'percentage_of_oils' => 50,
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'supply_id' => $additiveSupply->id,
        'supplier_listing_id' => $additiveSupply->supplier_listing_id,
        'ingredient_id' => $additiveSupply->supplierListing->ingredient_id,
        'phase' => Phases::Additives->value,
        'percentage_of_oils' => 5,
    ]);

    $production->update(['status' => ProductionStatus::Ongoing]);

    expect((float) $rawSupply->fresh()->quantity_out)->toBe(0.0)
        ->and((float) $additiveSupply->fresh()->quantity_out)->toBe(1.0)
        ->and((float) $masterbatchSupply->fresh()->quantity_out)->toBe(10.0)
        ->and(
            SuppliesMovement::query()
                ->where('production_id', $production->id)
                ->where('supply_id', $rawSupply->id)
                ->where('movement_type', 'out')
                ->exists()
        )->toBeFalse();
});

it('reconciles inventory when an ongoing production item changes supply lot', function () {
    $supplyA = Supply::factory()->inStock(50)->create();
    $supplyB = Supply::factory()->inStock(50)->create([
        'supplier_listing_id' => $supplyA->supplier_listing_id,
    ]);

    $production = Production::factory()->confirmed()->create([
        'planned_quantity' => 20,
    ]);

    $item = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'supply_id' => $supplyA->id,
        'supplier_listing_id' => $supplyA->supplier_listing_id,
        'ingredient_id' => $supplyA->supplierListing->ingredient_id,
        'phase' => Phases::Saponification->value,
        'percentage_of_oils' => 10,
    ]);

    $production->update(['status' => ProductionStatus::Ongoing]);

    expect((float) $supplyA->fresh()->quantity_out)->toBe(2.0)
        ->and((float) $supplyB->fresh()->quantity_out)->toBe(0.0);

    $item->update([
        'supply_id' => $supplyB->id,
        'supplier_listing_id' => $supplyB->supplier_listing_id,
    ]);

    expect((float) $supplyA->fresh()->quantity_out)->toBe(0.0)
        ->and((float) $supplyB->fresh()->quantity_out)->toBe(2.0)
        ->and(
            SuppliesMovement::query()
                ->where('production_id', $production->id)
                ->where('movement_type', 'out')
                ->where('reason', ProductionConsumptionService::REASON_ONGOING_CONSUMPTION)
                ->count()
        )->toBe(1);
});

it('consumes packaging lot only when production is finished', function () {
    $packagingSupply = Supply::factory()->inStock(500)->create();

    $production = Production::factory()->confirmed()->create([
        'planned_quantity' => 26,
        'expected_units' => 300,
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'supply_id' => $packagingSupply->id,
        'supplier_listing_id' => $packagingSupply->supplier_listing_id,
        'ingredient_id' => $packagingSupply->supplierListing->ingredient_id,
        'phase' => Phases::Packaging->value,
        'calculation_mode' => FormulaItemCalculationMode::QuantityPerUnit->value,
        'percentage_of_oils' => 1,
    ]);

    $production->update(['status' => ProductionStatus::Ongoing]);

    expect((float) $packagingSupply->fresh()->quantity_out)->toBe(0.0);

    $production->update(['status' => ProductionStatus::Finished]);

    expect((float) $packagingSupply->fresh()->quantity_out)->toBe(300.0);
});
