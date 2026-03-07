<?php

use App\Enums\OrderStatus;
use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
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

it('marks items as ordered from wave referenced supplier orders', function () {
    $wave = ProductionWave::factory()->create();
    $production = Production::factory()->forWave($wave)->create();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'unit_weight' => 25,
    ]);

    $item = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 25,
        'procurement_status' => ProcurementStatus::NotOrdered,
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

    expect($item->fresh()->procurement_status)->toBe(ProcurementStatus::Ordered);
});

it('marks items as received when wave referenced order item is moved to stock', function () {
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

    $item = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 20,
        'procurement_status' => ProcurementStatus::NotOrdered,
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

    expect($item->fresh()->procurement_status)->toBe(ProcurementStatus::Received);
});

it('does not mark items as ordered from draft orders', function () {
    $wave = ProductionWave::factory()->create();
    $production = Production::factory()->forWave($wave)->create();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'unit_weight' => 25,
    ]);

    $item = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 25,
        'procurement_status' => ProcurementStatus::NotOrdered,
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

    expect($item->fresh()->procurement_status)->toBe(ProcurementStatus::NotOrdered);
});

it('does not update items belonging to cancelled productions', function () {
    $wave = ProductionWave::factory()->create();
    $production = Production::factory()->forWave($wave)->create([
        'status' => ProductionStatus::Cancelled,
    ]);
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'unit_weight' => 25,
    ]);

    $item = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 25,
        'procurement_status' => ProcurementStatus::NotOrdered,
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

    expect($item->fresh()->procurement_status)->toBe(ProcurementStatus::NotOrdered);
});

it('keeps manually marked items as ordered without supplier order', function () {
    $wave = ProductionWave::factory()->create();
    $product = Product::factory()->create();
    $formula = Formula::query()->create([
        'name' => 'Formula manual ordered',
        'slug' => 'formula-manual-ordered',
        'code' => 'FRM-WAVE-001',
        'is_active' => true,
    ]);

    $production = Production::query()->create([
        'production_wave_id' => $wave->id,
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'batch_number' => 'T93001',
        'slug' => 'batch-manual-ordered',
        'status' => ProductionStatus::Planned,
        'sizing_mode' => SizingMode::OilWeight,
        'planned_quantity' => 20,
        'expected_units' => 100,
        'production_date' => now()->toDateString(),
        'ready_date' => now()->addDay()->toDateString(),
        'organic' => true,
    ]);
    $ingredient = Ingredient::factory()->create();

    $item = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 10,
        'procurement_status' => ProcurementStatus::NotOrdered,
        'is_order_marked' => true,
    ]);

    waveRequirementStatusService()->syncForWave($wave);

    expect($item->fresh()->procurement_status)->toBe(ProcurementStatus::Ordered);
});

it('re-synchronizes previous wave when a supplier order is re-linked', function () {
    $waveA = ProductionWave::factory()->create();
    $waveB = ProductionWave::factory()->create();
    $production = Production::factory()->forWave($waveA)->create();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'unit_weight' => 25,
    ]);

    $item = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 25,
        'procurement_status' => ProcurementStatus::NotOrdered,
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'production_wave_id' => $waveA->id,
        'order_status' => OrderStatus::Passed,
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'quantity' => 1,
        'unit_weight' => 25,
    ]);

    waveRequirementStatusService()->syncForWave($waveA);

    expect($item->fresh()->procurement_status)->toBe(ProcurementStatus::Ordered);

    $order->update([
        'production_wave_id' => $waveB->id,
    ]);

    expect($item->fresh()->procurement_status)->toBe(ProcurementStatus::NotOrdered);
});

it('returns ingredient options for wave order-mark action', function () {
    $wave = ProductionWave::factory()->create();
    $production = Production::factory()->forWave($wave)->create();
    $ingredientA = Ingredient::factory()->create(['name' => 'Huile coco']);
    $ingredientB = Ingredient::factory()->create(['name' => 'Soude']);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredientA->id,
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredientB->id,
    ]);

    $options = waveRequirementStatusService()->getIngredientOptionsForWave($wave);

    expect($options)->toHaveKey($ingredientA->id)
        ->and($options)->toHaveKey($ingredientB->id);
});

it('marks only not ordered items for selected wave ingredients', function () {
    $wave = ProductionWave::factory()->create();
    $production = Production::factory()->forWave($wave)->create();
    $ingredientA = Ingredient::factory()->create();
    $ingredientB = Ingredient::factory()->create();

    $itemToMark = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredientA->id,
        'procurement_status' => ProcurementStatus::NotOrdered,
        'is_order_marked' => false,
    ]);

    $itemAlreadyOrdered = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredientA->id,
        'procurement_status' => ProcurementStatus::Ordered,
        'is_order_marked' => false,
    ]);

    $itemOtherIngredient = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredientB->id,
        'procurement_status' => ProcurementStatus::NotOrdered,
        'is_order_marked' => false,
    ]);

    $updated = waveRequirementStatusService()->markNotOrderedItemsAsOrderedForIngredients($wave, [$ingredientA->id]);

    expect($updated)->toBe(1)
        ->and($itemToMark->fresh()->is_order_marked)->toBeTrue()
        ->and($itemToMark->fresh()->procurement_status)->toBe(ProcurementStatus::Ordered)
        ->and($itemAlreadyOrdered->fresh()->is_order_marked)->toBeFalse()
        ->and($itemOtherIngredient->fresh()->is_order_marked)->toBeFalse();
});
