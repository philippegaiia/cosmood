<?php

use App\Enums\AllocationStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
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
    })->throws(\InvalidArgumentException::class, 'has no available quantity');

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
    })->throws(\InvalidArgumentException::class, 'already fully allocated');
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
        \App\Models\Supply\SuppliesMovement::factory()->create([
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
    })->throws(\InvalidArgumentException::class, 'Only reserved allocations can be released');
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
        \App\Models\Supply\SuppliesMovement::factory()->create([
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
        \App\Models\Supply\SuppliesMovement::factory()->create([
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
        \App\Models\Supply\SuppliesMovement::factory()->create([
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
        \App\Models\Supply\SuppliesMovement::factory()->create([
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
            ->and((float) $splitItem2->percentage_of_oils)->toBe(20.0); // 30 * (20/30)
    });
});
