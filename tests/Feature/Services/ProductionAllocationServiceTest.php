<?php

use App\Enums\AllocationStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\SuppliesMovement;
use App\Models\Supply\Supply;
use App\Services\Production\ProductionAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ProductionAllocationService::class);
});

describe('allocate', function () {
    it('creates allocation from supply to item', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create([
            'required_quantity' => 10.0,
            'allocation_status' => AllocationStatus::Unassigned,
        ]);

        $allocation = $this->service->allocate($item, $supply, 10.0);

        expect((float) $allocation->quantity)->toBe(10.0)
            ->and($allocation->status)->toBe('reserved')
            ->and($allocation->supply_id)->toBe($supply->id)
            ->and($allocation->production_item_id)->toBe($item->id);

        expect($item->fresh()->allocation_status)->toBe(AllocationStatus::Allocated);
    });

    it('syncs the production item supply traceability when allocating', function () {
        $supply = Supply::factory()->inStock(50.0)->create([
            'batch_number' => 'LOT-ALLOC-001',
        ]);
        $item = ProductionItem::factory()->create([
            'required_quantity' => 10.0,
            'allocation_status' => AllocationStatus::Unassigned,
            'supply_id' => null,
            'supply_batch_number' => null,
            'is_supplied' => false,
        ]);

        $this->service->allocate($item, $supply, 10.0);

        expect($item->fresh()->supply_id)->toBe($supply->id)
            ->and($item->fresh()->supply_batch_number)->toBe('LOT-ALLOC-001')
            ->and($item->fresh()->is_supplied)->toBeTrue();
    });

    it('creates stock movement when allocating', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create([
            'required_quantity' => 10.0,
            'allocation_status' => AllocationStatus::Unassigned,
        ]);

        $this->service->allocate($item, $supply, 10.0);

        $movement = SuppliesMovement::query()
            ->where('supply_id', $supply->id)
            ->where('production_id', $item->production_id)
            ->where('movement_type', 'allocation')
            ->first();

        expect($movement)->not->toBeNull()
            ->and((float) $movement->quantity)->toBe(10.0)
            ->and($movement->reason)->toBe('Reserved for production');
    });

    it('creates allocation movement when allocating', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create([
            'required_quantity' => 10.0,
            'allocation_status' => AllocationStatus::Unassigned,
        ]);

        $this->service->allocate($item, $supply, 10.0);

        expect($supply->fresh()->getAllocatedQuantity())->toBe(10.0);
    });

    it('allocates partial quantity when supply insufficient', function () {
        $supply = Supply::factory()->inStock(15.0)->create();
        $item = ProductionItem::factory()->create([
            'required_quantity' => 30.0,
            'allocation_status' => AllocationStatus::Unassigned,
        ]);

        $allocation = $this->service->allocate($item, $supply);

        expect((float) $allocation->quantity)->toBe(15.0);
        expect($item->fresh()->allocation_status)->toBe(AllocationStatus::Partial);
    });

    it('allocates full required quantity when not specified', function () {
        $supply = Supply::factory()->inStock(100.0)->create();
        $item = ProductionItem::factory()->create([
            'required_quantity' => 25.0,
            'allocation_status' => AllocationStatus::Unassigned,
        ]);

        $allocation = $this->service->allocate($item, $supply);

        expect((float) $allocation->quantity)->toBe(25.0);
    });

    it('allows multiple allocations from different supplies', function () {
        $supply1 = Supply::factory()->inStock(20.0)->create();
        $supply2 = Supply::factory()->inStock(30.0)->create();
        $item = ProductionItem::factory()->create([
            'required_quantity' => 40.0,
            'allocation_status' => AllocationStatus::Unassigned,
        ]);

        $allocation1 = $this->service->allocate($item, $supply1);
        $allocation2 = $this->service->allocate($item, $supply2);

        expect((float) $allocation1->quantity)->toBe(20.0)
            ->and((float) $allocation2->quantity)->toBe(20.0);

        expect($item->fresh()->allocation_status)->toBe(AllocationStatus::Allocated);
    });

    it('throws when supply has no available quantity', function () {
        $supply = Supply::factory()->inStock(0.0)->create();
        $item = ProductionItem::factory()->create([
            'required_quantity' => 10.0,
        ]);

        $this->service->allocate($item, $supply);
    })->throws(InvalidArgumentException::class, 'has no available quantity');

    it('throws when item is already fully allocated', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create([
            'required_quantity' => 10.0,
            'allocation_status' => AllocationStatus::Allocated,
        ]);

        ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 10.0,
            'status' => 'reserved',
        ]);

        $supply2 = Supply::factory()->inStock(50.0)->create();

        $this->service->allocate($item, $supply2);
    })->throws(InvalidArgumentException::class, 'already fully allocated');

    it('allocates a wave ingredient strictly by production date order', function () {
        $wave = ProductionWave::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
        ]);

        $firstProduction = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'production_date' => '2026-03-10',
        ]);

        $secondProduction = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'production_date' => '2026-03-11',
        ]);

        $firstItem = ProductionItem::factory()->create([
            'production_id' => $firstProduction->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 60.0,
            'allocation_status' => AllocationStatus::Unassigned,
        ]);

        $secondItem = ProductionItem::factory()->create([
            'production_id' => $secondProduction->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 60.0,
            'allocation_status' => AllocationStatus::Unassigned,
        ]);

        $supply = Supply::factory()->inStock(100.0)->create([
            'supplier_listing_id' => $listing->id,
        ]);

        $summary = $this->service->allocateWaveIngredientStock($wave, $ingredient, 70.0);

        expect((float) ($summary['allocated_quantity'] ?? 0))->toBe(60.0)
            ->and((float) ($summary['remaining_quantity'] ?? 0))->toBe(10.0)
            ->and((int) ($summary['items_touched'] ?? 0))->toBe(1)
            ->and((float) $firstItem->fresh()->getTotalAllocatedQuantity())->toBe(60.0)
            ->and((float) $secondItem->fresh()->getTotalAllocatedQuantity())->toBe(0.0)
            ->and((float) $supply->fresh()->getAllocatedQuantity())->toBe(60.0);
    });

    it('allocates an orphan production ingredient strictly by item order', function () {
        $production = Production::factory()->orphan()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
        ]);

        $firstItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 60.0,
            'allocation_status' => AllocationStatus::Unassigned,
            'sort' => 1,
        ]);

        $secondItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 60.0,
            'allocation_status' => AllocationStatus::Unassigned,
            'sort' => 2,
        ]);

        $supply = Supply::factory()->inStock(100.0)->create([
            'supplier_listing_id' => $listing->id,
        ]);

        $summary = $this->service->allocateProductionIngredientStock($production, $ingredient, 70.0);

        expect((float) ($summary['allocated_quantity'] ?? 0))->toBe(60.0)
            ->and((float) ($summary['remaining_quantity'] ?? 0))->toBe(10.0)
            ->and((int) ($summary['items_touched'] ?? 0))->toBe(1)
            ->and((float) $firstItem->fresh()->getTotalAllocatedQuantity())->toBe(60.0)
            ->and((float) $secondItem->fresh()->getTotalAllocatedQuantity())->toBe(0.0)
            ->and((float) $supply->fresh()->getAllocatedQuantity())->toBe(60.0);
    });

    it('allocates orphan stock against the uncovered remainder after linked purchase orders', function () {
        $production = Production::factory()->orphan()->create([
            'production_date' => '2026-03-12',
        ]);
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 50.0,
            'allocation_status' => AllocationStatus::Unassigned,
            'sort' => 1,
        ]);

        $supply = Supply::factory()->inStock(20.0)->create([
            'supplier_listing_id' => $listing->id,
        ]);

        $order = SupplierOrder::factory()->confirmed()->create();

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $order->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 30,
            'unit_weight' => 1,
            'allocated_to_production_id' => $production->id,
            'allocated_quantity' => 30,
            'moved_to_stock_at' => null,
        ]);

        $summary = $this->service->allocateProductionIngredientStock($production, $ingredient);

        expect((float) ($summary['requested_quantity'] ?? 0))->toBe(20.0)
            ->and((float) ($summary['allocated_quantity'] ?? 0))->toBe(20.0)
            ->and((float) ($summary['remaining_quantity'] ?? 0))->toBe(0.0)
            ->and((float) $item->fresh()->getTotalAllocatedQuantity())->toBe(20.0)
            ->and((float) $supply->fresh()->getAllocatedQuantity())->toBe(20.0);
    });

    it('allocates a received lot to its wave using only that lot', function () {
        $wave = ProductionWave::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
        ]);

        $production = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'production_date' => '2026-03-12',
        ]);
        $production->productionItems()->delete();

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 40.0,
            'allocation_status' => AllocationStatus::Unassigned,
        ]);

        $targetSupply = Supply::factory()->inStock(50.0)->create([
            'supplier_listing_id' => $listing->id,
            'batch_number' => 'LOT-TARGET-001',
        ]);

        $otherSupply = Supply::factory()->inStock(80.0)->create([
            'supplier_listing_id' => $listing->id,
            'batch_number' => 'LOT-OTHER-001',
        ]);

        $summary = $this->service->allocateSupplyToWave($targetSupply, $wave, 40.0);

        expect((float) ($summary['allocated_quantity'] ?? 0))->toBe(40.0)
            ->and($item->fresh()->allocation_status)->toBe(AllocationStatus::Allocated)
            ->and($item->fresh()->supply_batch_number)->toBe('LOT-TARGET-001')
            ->and((float) $targetSupply->fresh()->getAllocatedQuantity())->toBe(40.0)
            ->and((float) $otherSupply->fresh()->getAllocatedQuantity())->toBe(0.0);
    });

    it('allocates a received lot to an orphan production using only that lot', function () {
        $production = Production::factory()->orphan()->create([
            'production_date' => '2026-03-12',
        ]);
        $production->productionItems()->delete();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 25.0,
            'allocation_status' => AllocationStatus::Unassigned,
        ]);

        $targetSupply = Supply::factory()->inStock(30.0)->create([
            'supplier_listing_id' => $listing->id,
            'batch_number' => 'LOT-ORPHAN-001',
        ]);

        $otherSupply = Supply::factory()->inStock(30.0)->create([
            'supplier_listing_id' => $listing->id,
            'batch_number' => 'LOT-ORPHAN-002',
        ]);

        $summary = $this->service->allocateSupplyToProduction($targetSupply, $production, 25.0);

        expect((float) ($summary['allocated_quantity'] ?? 0))->toBe(25.0)
            ->and($item->fresh()->allocation_status)->toBe(AllocationStatus::Allocated)
            ->and($item->fresh()->supply_batch_number)->toBe('LOT-ORPHAN-001')
            ->and((float) $targetSupply->fresh()->getAllocatedQuantity())->toBe(25.0)
            ->and((float) $otherSupply->fresh()->getAllocatedQuantity())->toBe(0.0);
    });

    it('allocates a received lot to an orphan production after linked purchase orders', function () {
        $production = Production::factory()->orphan()->create([
            'production_date' => '2026-03-12',
        ]);
        $production->productionItems()->delete();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 50.0,
            'allocation_status' => AllocationStatus::Unassigned,
        ]);

        $targetSupply = Supply::factory()->inStock(20.0)->create([
            'supplier_listing_id' => $listing->id,
            'batch_number' => 'LOT-ORPHAN-LINKED-001',
        ]);

        $order = SupplierOrder::factory()->confirmed()->create();

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $order->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 30,
            'unit_weight' => 1,
            'allocated_to_production_id' => $production->id,
            'allocated_quantity' => 30,
            'moved_to_stock_at' => null,
        ]);

        $summary = $this->service->allocateSupplyToProduction($targetSupply, $production);

        expect((float) ($summary['requested_quantity'] ?? 0))->toBe(20.0)
            ->and((float) ($summary['allocated_quantity'] ?? 0))->toBe(20.0)
            ->and((float) ($summary['remaining_quantity'] ?? 0))->toBe(0.0)
            ->and($item->fresh()->allocation_status)->toBe(AllocationStatus::Partial)
            ->and($item->fresh()->supply_batch_number)->toBe('LOT-ORPHAN-LINKED-001')
            ->and((float) $targetSupply->fresh()->getAllocatedQuantity())->toBe(20.0);
    });
});

describe('release', function () {
    it('releases a reserved allocation', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create([
            'required_quantity' => 10.0,
        ]);
        $allocation = ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 10.0,
            'status' => 'reserved',
        ]);

        $this->service->release($allocation);

        expect($allocation->fresh()->status)->toBe('released');
        expect($item->fresh()->allocation_status)->toBe(AllocationStatus::Unassigned);
    });

    it('clears the production item supply traceability when releasing the last allocation', function () {
        $supply = Supply::factory()->inStock(50.0)->create([
            'batch_number' => 'LOT-ALLOC-002',
        ]);
        $item = ProductionItem::factory()->create([
            'required_quantity' => 10.0,
            'supply_id' => $supply->id,
            'supply_batch_number' => 'LOT-ALLOC-002',
            'is_supplied' => true,
        ]);
        $allocation = ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 10.0,
            'status' => 'reserved',
        ]);

        $this->service->release($allocation);

        expect($item->fresh()->supply_id)->toBeNull()
            ->and($item->fresh()->supply_batch_number)->toBeNull()
            ->and($item->fresh()->is_supplied)->toBeFalse();
    });

    it('deletes stock movement when releasing', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create([
            'required_quantity' => 10.0,
        ]);
        $allocation = ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 10.0,
            'status' => 'reserved',
        ]);

        // First create the allocation properly
        $this->service->release($allocation);

        $movement = SuppliesMovement::query()
            ->where('supply_id', $supply->id)
            ->where('production_id', $item->production_id)
            ->where('movement_type', 'allocation')
            ->first();

        expect($movement)->not->toBeNull()
            ->and((float) $movement->quantity)->toBe(-10.0);
    });

    it('creates negative allocation movement when releasing', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create([
            'required_quantity' => 10.0,
        ]);
        $allocation = ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 10.0,
            'status' => 'reserved',
        ]);

        // Create initial allocation movement
        SuppliesMovement::factory()->create([
            'supply_id' => $supply->id,
            'production_id' => $item->production_id,
            'movement_type' => 'allocation',
            'quantity' => 10.0,
        ]);

        $this->service->release($allocation);

        // Should have net 0 allocated (10 - 10 = 0)
        expect($supply->fresh()->getAllocatedQuantity())->toBe(0.0);
    });

    it('throws when trying to release consumed allocation', function () {
        $allocation = ProductionItemAllocation::factory()->consumed()->create();

        $this->service->release($allocation);
    })->throws(InvalidArgumentException::class, 'Only reserved allocations can be released');
});

describe('releaseAll', function () {
    it('releases all allocations for an item', function () {
        $item = ProductionItem::factory()->create(['required_quantity' => 30.0]);

        ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'quantity' => 15.0,
            'status' => 'reserved',
        ]);
        ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'quantity' => 15.0,
            'status' => 'reserved',
        ]);

        $this->service->releaseAll($item);

        expect($item->allocations()->where('status', 'released')->count())->toBe(2);
    });
});

describe('consume', function () {
    it('converts reserved allocations to consumed', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create();
        $allocation = ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 10.0,
            'status' => 'reserved',
        ]);

        $this->service->consume($item);

        expect($allocation->fresh()->status)->toBe('consumed');
        expect((float) $supply->fresh()->quantity_out)->toBe(10.0);
    });

    it('creates consumption movement when consuming', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create();
        $allocation = ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 10.0,
            'status' => 'reserved',
        ]);

        $this->service->consume($item);

        $movement = SuppliesMovement::query()
            ->where('supply_id', $supply->id)
            ->where('production_id', $item->production_id)
            ->where('movement_type', 'out')
            ->first();

        expect($movement)->not->toBeNull()
            ->and((float) $movement->quantity)->toBe(10.0)
            ->and($movement->reason)->toBe('Consumed in production');
    });

    it('creates negative allocation movement when consuming', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create();
        $allocation = ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 10.0,
            'status' => 'reserved',
        ]);

        // Create initial positive allocation movement
        SuppliesMovement::factory()->create([
            'supply_id' => $supply->id,
            'production_id' => $item->production_id,
            'movement_type' => 'allocation',
            'quantity' => 10.0,
        ]);

        $this->service->consume($item);

        // Should now have negative allocation movement to cancel it out
        $allocationMovement = SuppliesMovement::query()
            ->where('supply_id', $supply->id)
            ->where('production_id', $item->production_id)
            ->where('movement_type', 'allocation')
            ->where('quantity', -10.0)
            ->first();

        expect($allocationMovement)->not->toBeNull();
    });

    it('calculates allocated quantity correctly when consuming', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create();
        $allocation = ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 10.0,
            'status' => 'reserved',
        ]);

        // Create initial allocation movement
        SuppliesMovement::factory()->create([
            'supply_id' => $supply->id,
            'production_id' => $item->production_id,
            'movement_type' => 'allocation',
            'quantity' => 10.0,
        ]);

        $this->service->consume($item);

        // Should have net 0 allocated and consumption recorded
        expect($supply->fresh()->getAllocatedQuantity())->toBe(0.0);
    });

    it('does not affect already consumed allocations', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create();

        ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 10.0,
            'status' => 'consumed',
        ]);

        $this->service->consume($item);

        expect((float) $supply->fresh()->quantity_out)->toBe(0.0);
    });
});

describe('getAvailableSupplies', function () {
    it('returns supplies for ingredient ordered by expiry (FIFO)', function () {
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);

        $supply1 = Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity_in' => 20.0,
            'quantity_out' => 0,
            'allocated_quantity' => 0,
            'expiry_date' => now()->addYear(),
            'is_in_stock' => true,
        ]);

        $supply2 = Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity_in' => 30.0,
            'quantity_out' => 0,
            'allocated_quantity' => 0,
            'expiry_date' => now()->addMonths(6),
            'is_in_stock' => true,
        ]);

        $available = $this->service->getAvailableSupplies($ingredient);

        expect($available)
            ->toHaveCount(2)
            ->first()->id->toBe($supply2->id);
    });

    it('excludes supplies with no available quantity', function () {
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);

        $supply = Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity_in' => 20.0,
            'quantity_out' => 0,
            'is_in_stock' => true,
        ]);

        // Create allocation movement
        SuppliesMovement::factory()->create([
            'supply_id' => $supply->id,
            'movement_type' => 'allocation',
            'quantity' => 20.0,
        ]);

        $available = $this->service->getAvailableSupplies($ingredient);

        expect($available)->toBeEmpty();
    });
});

describe('preview', function () {
    it('shows allocation preview', function () {
        $supply = Supply::factory()->inStock(15.0)->create();
        $item = ProductionItem::factory()->create(['required_quantity' => 25.0]);

        $preview = $this->service->preview($item, $supply);

        expect($preview)
            ->required->toBe(25.0)
            ->available->toBe(15.0)
            ->can_fulfill->toBeFalse()
            ->shortage->toBe(10.0);
    });

    it('shows sufficient stock when available', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create(['required_quantity' => 25.0]);

        $preview = $this->service->preview($item, $supply);

        expect($preview)
            ->can_fulfill->toBeTrue()
            ->shortage->toBe(0.0);
    });
});

describe('getTotalAvailable', function () {
    it('sums available quantities across supplies', function () {
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);

        $supply1 = Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity_in' => 30.0,
            'quantity_out' => 5.0,
            'is_in_stock' => true,
        ]);

        // Create allocation movement
        SuppliesMovement::factory()->create([
            'supply_id' => $supply1->id,
            'movement_type' => 'allocation',
            'quantity' => 5.0,
        ]);

        Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity_in' => 20.0,
            'quantity_out' => 0,
            'is_in_stock' => true,
        ]);

        expect($this->service->getTotalAvailable($ingredient))->toBe(40.0);
    });
});

describe('allocate with released reuse', function () {
    it('reuses released allocation when reallocating same supply', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $item = ProductionItem::factory()->create(['required_quantity' => 10.0]);

        // First allocation
        $allocation1 = $this->service->allocate($item, $supply, 10.0);
        expect($allocation1->status)->toBe('reserved');

        // Release
        $this->service->release($allocation1);
        expect($allocation1->fresh()->status)->toBe('released');

        // Reallocate same supply
        $allocation2 = $this->service->allocate($item, $supply, 10.0);

        expect($allocation2->id)->toBe($allocation1->id) // Same record
            ->and($allocation2->status)->toBe('reserved');

        // Should only have one allocation record
        expect(ProductionItemAllocation::count())->toBe(1);
    });
});

describe('createSplitItem', function () {
    it('creates split item with proportional coefficient', function () {
        $production = Production::factory()->create(['planned_quantity' => 100.0]);
        $ingredient = Ingredient::factory()->create();

        $originalItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'percentage_of_oils' => 50.0, // 50% of 100kg = 50kg
            'required_quantity' => 50.0,
            'sort' => 1,
        ]);

        // Allocate 30kg (leaves 20kg unallocated)
        $supply = Supply::factory()->inStock(30.0)->create();
        $this->service->allocate($originalItem, $supply, 30.0);

        // Create split
        $splitItem = $this->service->createSplitItem($originalItem);

        // Original: 30kg allocated out of 50kg = 60% of original coefficient
        // Split: 20kg remaining out of 50kg = 40% of original coefficient
        expect((float) $originalItem->fresh()->percentage_of_oils)->toBe(30.0) // 50 * (30/50)
            ->and((float) $splitItem->percentage_of_oils)->toBe(20.0) // 50 * (20/50)
            ->and((float) $originalItem->fresh()->required_quantity)->toBe(30.0)
            ->and((float) $splitItem->fresh()->required_quantity)->toBe(20.0)
            ->and($splitItem->split_from_item_id)->toBe($originalItem->id)
            ->and($splitItem->split_root_item_id)->toBe($originalItem->id)
            ->and($splitItem->sort)->toBe(2);
    });

    it('handles 3-way split correctly', function () {
        $production = Production::factory()->create(['planned_quantity' => 100.0]);
        $ingredient = Ingredient::factory()->create();

        $rootItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'percentage_of_oils' => 50.0,
            'required_quantity' => 50.0,
            'sort' => 1,
        ]);

        // First split: allocate 20kg, create split for 30kg
        $supply1 = Supply::factory()->inStock(20.0)->create();
        $this->service->allocate($rootItem, $supply1, 20.0);
        $splitItem1 = $this->service->createSplitItem($rootItem);

        // Second split: allocate 10kg from split, create split for 20kg
        $supply2 = Supply::factory()->inStock(10.0)->create();
        $this->service->allocate($splitItem1, $supply2, 10.0);
        $splitItem2 = $this->service->createSplitItem($splitItem1);

        // Verify root item tracking
        expect($splitItem1->split_root_item_id)->toBe($rootItem->id)
            ->and($splitItem2->split_root_item_id)->toBe($rootItem->id)
            ->and($splitItem2->split_from_item_id)->toBe($splitItem1->id);

        // Verify coefficients are proportional
        expect((float) $rootItem->fresh()->percentage_of_oils)->toBe(20.0) // 50 * (20/50)
            ->and((float) $splitItem1->fresh()->percentage_of_oils)->toBe(10.0) // 30 * (10/30)
            ->and((float) $splitItem2->percentage_of_oils)->toBe(20.0) // 30 * (20/30)
            ->and((float) $rootItem->fresh()->required_quantity)->toBe(20.0)
            ->and((float) $splitItem1->fresh()->required_quantity)->toBe(10.0)
            ->and((float) $splitItem2->fresh()->required_quantity)->toBe(20.0);
    });

    it('prevents duplicate split when there is no remaining quantity', function () {
        $production = Production::factory()->create(['planned_quantity' => 100.0]);
        $ingredient = Ingredient::factory()->create();

        $rootItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'percentage_of_oils' => 50.0,
            'required_quantity' => 50.0,
        ]);

        $supply = Supply::factory()->inStock(30.0)->create();
        $this->service->allocate($rootItem, $supply, 30.0);

        $this->service->createSplitItem($rootItem);

        $this->service->createSplitItem($rootItem->fresh());
    })->throws(InvalidArgumentException::class, 'No unallocated quantity to split');

    it('prevents split when there is no allocated quantity', function () {
        $production = Production::factory()->create(['planned_quantity' => 100.0]);
        $ingredient = Ingredient::factory()->create();

        $rootItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'percentage_of_oils' => 50.0,
            'required_quantity' => 50.0,
        ]);

        $this->service->createSplitItem($rootItem);
    })->throws(InvalidArgumentException::class, 'Cannot split an item without allocated quantity');

    it('prevents split when allocations are consumed', function () {
        $production = Production::factory()->create(['planned_quantity' => 100.0]);
        $ingredient = Ingredient::factory()->create();

        $rootItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'percentage_of_oils' => 50.0,
            'required_quantity' => 50.0,
        ]);

        $supply = Supply::factory()->inStock(30.0)->create();
        $this->service->allocate($rootItem, $supply, 30.0);
        $this->service->consume($rootItem->fresh());

        $this->service->createSplitItem($rootItem->fresh());
    })->throws(InvalidArgumentException::class, 'Cannot split an item with consumed allocations');
});

describe('mergeSplitItem', function () {
    it('merges into nearest active ancestor when immediate parent is deleted', function () {
        $production = Production::factory()->create(['planned_quantity' => 100.0]);
        $ingredient = Ingredient::factory()->create();

        $rootItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'percentage_of_oils' => 50.0,
            'required_quantity' => 50.0,
            'sort' => 1,
        ]);

        $supply1 = Supply::factory()->inStock(20.0)->create();
        $this->service->allocate($rootItem, $supply1, 20.0);
        $splitItem1 = $this->service->createSplitItem($rootItem);

        $supply2 = Supply::factory()->inStock(10.0)->create();
        $this->service->allocate($splitItem1, $supply2, 10.0);
        $splitItem2 = $this->service->createSplitItem($splitItem1);

        $this->service->releaseAll($splitItem1->fresh());
        $splitItem1->fresh()->delete();

        $mergedParent = $this->service->mergeSplitItem($splitItem2->fresh());

        expect($mergedParent->id)->toBe($rootItem->id)
            ->and((float) $rootItem->fresh()->percentage_of_oils)->toBe(40.0)
            ->and((float) $rootItem->fresh()->required_quantity)->toBe(40.0)
            ->and(ProductionItem::find($splitItem2->id))->toBeNull();
    });

    it('reparents direct children before deleting merged split item', function () {
        $production = Production::factory()->create(['planned_quantity' => 100.0]);
        $ingredient = Ingredient::factory()->create();

        $rootItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'percentage_of_oils' => 50.0,
            'required_quantity' => 50.0,
            'sort' => 1,
        ]);

        $supply1 = Supply::factory()->inStock(20.0)->create();
        $this->service->allocate($rootItem, $supply1, 20.0);
        $splitItem1 = $this->service->createSplitItem($rootItem);

        $supply2 = Supply::factory()->inStock(10.0)->create();
        $this->service->allocate($splitItem1, $supply2, 10.0);
        $splitItem2 = $this->service->createSplitItem($splitItem1);

        $mergedParent = $this->service->mergeSplitItem($splitItem1);

        expect($mergedParent->id)->toBe($rootItem->id)
            ->and((float) $rootItem->fresh()->percentage_of_oils)->toBe(30.0)
            ->and((float) $rootItem->fresh()->required_quantity)->toBe(30.0)
            ->and(ProductionItem::find($splitItem1->id))->toBeNull();

        $reparentedChild = ProductionItem::find($splitItem2->id);

        expect($reparentedChild)->not->toBeNull()
            ->and($reparentedChild->split_from_item_id)->toBe($rootItem->id)
            ->and($reparentedChild->split_root_item_id)->toBe($rootItem->id);
    });

    it('keeps split item standalone when its root item is deleted', function () {
        $production = Production::factory()->create(['planned_quantity' => 100.0]);
        $ingredient = Ingredient::factory()->create();

        $rootItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'percentage_of_oils' => 50.0,
            'required_quantity' => 50.0,
            'sort' => 1,
        ]);

        $supply1 = Supply::factory()->inStock(20.0)->create();
        $this->service->allocate($rootItem, $supply1, 20.0);
        $splitItem = $this->service->createSplitItem($rootItem);

        $this->service->releaseAll($rootItem->fresh());
        $rootItem->fresh()->delete();

        $splitItem = $splitItem->fresh();

        expect($splitItem)->not->toBeNull()
            ->and($splitItem->split_from_item_id)->toBeNull()
            ->and($splitItem->split_root_item_id)->toBeNull();

        $result = $this->service->mergeSplitItem($splitItem);

        expect($result->id)->toBe($splitItem->id)
            ->and($result->split_from_item_id)->toBeNull()
            ->and($result->split_root_item_id)->toBeNull()
            ->and(ProductionItem::find($splitItem->id))->not->toBeNull();
    });

    it('prevents merging a split item with consumed allocations', function () {
        $production = Production::factory()->create(['planned_quantity' => 100.0]);
        $ingredient = Ingredient::factory()->create();

        $rootItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'percentage_of_oils' => 50.0,
            'required_quantity' => 50.0,
            'sort' => 1,
        ]);

        $supply1 = Supply::factory()->inStock(20.0)->create();
        $this->service->allocate($rootItem, $supply1, 20.0);
        $splitItem = $this->service->createSplitItem($rootItem);

        $supply2 = Supply::factory()->inStock(30.0)->create();
        $this->service->allocate($splitItem, $supply2, 30.0);
        $this->service->consume($splitItem->fresh());

        $this->service->mergeSplitItem($splitItem->fresh());
    })->throws(InvalidArgumentException::class, 'Cannot merge a split item with consumed allocations');
});
