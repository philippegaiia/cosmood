<?php

use App\Enums\FormulaItemCalculationMode;
use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Supply\SuppliesMovement;
use App\Models\Supply\Supply;
use App\Services\Production\ProductionConsumptionService;
use App\Services\Production\ProductionReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reserves selected lots while production is planned', function () {
    $supply = Supply::factory()->inStock(50)->create();

    $production = Production::factory()->planned()->create([
        'planned_quantity' => 20,
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'supply_id' => $supply->id,
        'supplier_listing_id' => $supply->supplier_listing_id,
        'ingredient_id' => $supply->supplierListing->ingredient_id,
        'phase' => Phases::Saponification->value,
        'percentage_of_oils' => 10,
    ]);

    expect((float) $supply->fresh()->allocated_quantity)->toBe(2.0)
        ->and($supply->fresh()->getAvailableQuantity())->toBe(48.0)
        ->and(
            SuppliesMovement::query()
                ->where('production_id', $production->id)
                ->where('movement_type', ProductionReservationService::RESERVATION_MOVEMENT_TYPE)
                ->where('reason', ProductionReservationService::RESERVATION_REASON)
                ->count()
        )->toBe(1);
});

it('consumes non-packaging on ongoing and keeps packaging reserved', function () {
    $oilSupply = Supply::factory()->inStock(50)->create();
    $packagingSupply = Supply::factory()->inStock(500)->create();

    $production = Production::factory()->confirmed()->create([
        'planned_quantity' => 20,
        'expected_units' => 300,
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'supply_id' => $oilSupply->id,
        'supplier_listing_id' => $oilSupply->supplier_listing_id,
        'ingredient_id' => $oilSupply->supplierListing->ingredient_id,
        'phase' => Phases::Saponification->value,
        'percentage_of_oils' => 10,
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

    expect((float) $oilSupply->fresh()->allocated_quantity)->toBe(0.0)
        ->and((float) $oilSupply->fresh()->quantity_out)->toBe(2.0)
        ->and((float) $packagingSupply->fresh()->allocated_quantity)->toBe(300.0)
        ->and((float) $packagingSupply->fresh()->quantity_out)->toBe(0.0)
        ->and(
            SuppliesMovement::query()
                ->where('production_id', $production->id)
                ->where('movement_type', 'out')
                ->where('reason', ProductionConsumptionService::REASON_ONGOING_CONSUMPTION)
                ->count()
        )->toBe(1);
});

it('releases reservations when production is cancelled', function () {
    $supply = Supply::factory()->inStock(50)->create();

    $production = Production::factory()->confirmed()->create([
        'planned_quantity' => 20,
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'supply_id' => $supply->id,
        'supplier_listing_id' => $supply->supplier_listing_id,
        'ingredient_id' => $supply->supplierListing->ingredient_id,
        'phase' => Phases::Saponification->value,
        'percentage_of_oils' => 10,
    ]);

    expect((float) $supply->fresh()->allocated_quantity)->toBe(2.0);

    $production->update(['status' => ProductionStatus::Cancelled]);

    expect((float) $supply->fresh()->allocated_quantity)->toBe(0.0)
        ->and(
            SuppliesMovement::query()
                ->where('production_id', $production->id)
                ->where('movement_type', ProductionReservationService::RESERVATION_MOVEMENT_TYPE)
                ->where('reason', ProductionReservationService::RESERVATION_REASON)
                ->count()
        )->toBe(0);
});

it('rolls back reservations and staged consumptions when deleting an ongoing production', function () {
    $oilSupply = Supply::factory()->inStock(50)->create();
    $packagingSupply = Supply::factory()->inStock(500)->create();

    $production = Production::factory()->confirmed()->create([
        'planned_quantity' => 20,
        'expected_units' => 300,
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'supply_id' => $oilSupply->id,
        'supplier_listing_id' => $oilSupply->supplier_listing_id,
        'ingredient_id' => $oilSupply->supplierListing->ingredient_id,
        'phase' => Phases::Saponification->value,
        'percentage_of_oils' => 10,
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

    expect((float) $oilSupply->fresh()->quantity_out)->toBe(2.0)
        ->and((float) $packagingSupply->fresh()->allocated_quantity)->toBe(300.0);

    $production->delete();

    expect((float) $oilSupply->fresh()->quantity_out)->toBe(0.0)
        ->and((float) $oilSupply->fresh()->allocated_quantity)->toBe(0.0)
        ->and((float) $packagingSupply->fresh()->quantity_out)->toBe(0.0)
        ->and((float) $packagingSupply->fresh()->allocated_quantity)->toBe(0.0)
        ->and(
            SuppliesMovement::query()
                ->where('production_id', $production->id)
                ->whereIn('movement_type', [
                    ProductionReservationService::RESERVATION_MOVEMENT_TYPE,
                    'out',
                ])
                ->count()
        )->toBe(0);
});
