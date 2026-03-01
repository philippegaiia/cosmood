<?php

use App\Enums\AllocationStatus;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
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

        Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity_in' => 20.0,
            'quantity_out' => 0,
            'allocated_quantity' => 20.0,
            'is_in_stock' => true,
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

        Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity_in' => 30.0,
            'quantity_out' => 5.0,
            'allocated_quantity' => 5.0,
            'is_in_stock' => true,
        ]);

        Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity_in' => 20.0,
            'quantity_out' => 0,
            'allocated_quantity' => 0,
            'is_in_stock' => true,
        ]);

        expect($this->service->getTotalAvailable($ingredient))->toBe(40.0);
    });
});
