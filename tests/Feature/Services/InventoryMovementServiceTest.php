<?php

use App\Models\Production\Production;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use App\Models\User;
use App\Services\InventoryMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inventoryMovementService(): InventoryMovementService
{
    return app(InventoryMovementService::class);
}

it('records inbound movement from supplier order item', function () {
    $user = User::factory()->create();
    $item = SupplierOrderItem::factory()->create([
        'quantity' => 2,
        'unit_weight' => 25,
    ]);
    $supply = Supply::factory()->create([
        'supplier_order_item_id' => $item->id,
        'supplier_listing_id' => $item->supplier_listing_id,
    ]);

    $movement = inventoryMovementService()->recordInboundFromOrderItem($supply, $item, $user);

    expect($movement->movement_type)->toBe('in')
        ->and((float) $movement->quantity)->toBe(50.0)
        ->and($movement->supply_id)->toBe($supply->id)
        ->and($movement->supplier_order_item_id)->toBe($item->id)
        ->and($movement->user_id)->toBe($user->id);
});

it('records outbound movement to production', function () {
    $user = User::factory()->create();
    $production = Production::factory()->create();
    $supply = Supply::factory()->create();

    $movement = inventoryMovementService()->recordOutboundToProduction($supply, $production, 12.5, $user, 'Manual usage');

    expect($movement->movement_type)->toBe('out')
        ->and((float) $movement->quantity)->toBe(12.5)
        ->and($movement->production_id)->toBe($production->id)
        ->and($movement->reason)->toBe('Manual usage');
});

it('records adjustment movement', function () {
    $user = User::factory()->create();
    $supply = Supply::factory()->create();

    $movement = inventoryMovementService()->recordAdjustment($supply, -3.2, 'Physical count correction', $user);

    expect($movement->movement_type)->toBe('adjustment')
        ->and((float) $movement->quantity)->toBe(-3.2)
        ->and($movement->reason)->toBe('Physical count correction')
        ->and($movement->user_id)->toBe($user->id);
});

it('receives supplier order item into stock once and records movement', function () {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create(['price' => 3.40]);
    $listing = SupplierListing::factory()->create([
        'ingredient_id' => $ingredient->id,
        'price' => 3.40,
    ]);

    $item = SupplierOrderItem::factory()->create([
        'supplier_listing_id' => $listing->id,
        'quantity' => 4,
        'unit_weight' => 12.5,
        'unit_price' => 9.9,
        'batch_number' => 'LOT-001',
        'expiry_date' => now()->addYear(),
    ]);

    $supply = inventoryMovementService()->receiveOrderItemIntoStock(
        $item,
        'PO-TEST-0001',
        now()->toDateString(),
        $user,
    );

    expect((float) $supply->quantity_in)->toBe(50.0)
        ->and($supply->supplier_order_item_id)->toBe($item->id)
        ->and($supply->is_in_stock)->toBeTrue();

    $item = $item->fresh();

    expect($item->is_in_supplies)->toBe('Stock')
        ->and($item->moved_to_stock_at)->not->toBeNull()
        ->and($item->moved_to_stock_by)->toBe($user->id)
        ->and((float) $item->supplierListing->fresh()->price)->toBe(9.9)
        ->and((float) $ingredient->fresh()->price)->toBe(9.9);

    expect($supply->movements()->count())->toBe(1)
        ->and($supply->movements()->first()->movement_type)->toBe('in');
});

it('prevents receiving the same supplier order item twice', function () {
    $item = SupplierOrderItem::factory()->create([
        'is_in_supplies' => 'Stock',
        'moved_to_stock_at' => now(),
    ]);

    inventoryMovementService()->receiveOrderItemIntoStock(
        $item,
        'PO-TEST-0002',
        now()->toDateString(),
    );
})->throws(RuntimeException::class, 'Supplier order item is already in stock.');
