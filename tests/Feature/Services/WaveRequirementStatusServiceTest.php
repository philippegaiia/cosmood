<?php

use App\Enums\OrderStatus;
use App\Enums\RequirementStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionIngredientRequirement;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\User;
use App\Services\InventoryMovementService;
use App\Services\Production\WaveRequirementStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function waveRequirementStatusService(): WaveRequirementStatusService
{
    return app(WaveRequirementStatusService::class);
}

it('marks requirements as ordered from wave referenced supplier orders', function () {
    $wave = ProductionWave::factory()->create();
    $production = Production::factory()->forWave($wave)->create();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'unit_weight' => 25,
    ]);

    $requirement = ProductionIngredientRequirement::factory()->create([
        'production_id' => $production->id,
        'production_wave_id' => $wave->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 25,
        'status' => RequirementStatus::NotOrdered,
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'production_wave_id' => $wave->id,
        'order_status' => OrderStatus::Passed,
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'quantity' => 1,
        'unit_weight' => 25,
    ]);

    waveRequirementStatusService()->syncForWave($wave);

    expect($requirement->fresh()->status)->toBe(RequirementStatus::Ordered);
});

it('marks requirements as received when wave referenced order item is moved to stock', function () {
    $wave = ProductionWave::factory()->create();
    $production = Production::factory()->forWave($wave)->create();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->create();
    $user = User::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'unit_weight' => 10,
    ]);

    $requirement = ProductionIngredientRequirement::factory()->create([
        'production_id' => $production->id,
        'production_wave_id' => $wave->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 20,
        'status' => RequirementStatus::NotOrdered,
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'production_wave_id' => $wave->id,
        'order_status' => OrderStatus::Checked,
        'delivery_date' => now()->toDateString(),
        'order_ref' => 'PO-WAVE-001',
    ]);

    $orderItem = SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'quantity' => 2,
        'unit_weight' => 10,
        'unit_price' => 6.5,
    ]);

    app(InventoryMovementService::class)->receiveOrderItemIntoStock(
        $orderItem,
        (string) $order->order_ref,
        (string) $order->delivery_date,
        $user,
    );

    expect($requirement->fresh()->status)->toBe(RequirementStatus::Received);
});

it('does not mark requirements as ordered from draft orders', function () {
    $wave = ProductionWave::factory()->create();
    $production = Production::factory()->forWave($wave)->create();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'unit_weight' => 25,
    ]);

    $requirement = ProductionIngredientRequirement::factory()->create([
        'production_id' => $production->id,
        'production_wave_id' => $wave->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 25,
        'status' => RequirementStatus::NotOrdered,
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'production_wave_id' => $wave->id,
        'order_status' => OrderStatus::Draft,
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'quantity' => 1,
        'unit_weight' => 25,
    ]);

    waveRequirementStatusService()->syncForWave($wave);

    expect($requirement->fresh()->status)->toBe(RequirementStatus::NotOrdered);
});
