<?php

use App\Enums\RequirementStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionIngredientRequirement;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\Supply;
use App\Services\Production\AllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(AllocationService::class);
});

describe('allocate', function () {
    it('allocates supply to requirement', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $requirement = ProductionIngredientRequirement::factory()->create([
            'required_quantity' => 10.0,
            'status' => RequirementStatus::Received,
        ]);

        $this->service->allocate($requirement, $supply, 10.0);

        $requirement = $requirement->fresh();
        expect($requirement)
            ->status->toBe(RequirementStatus::Allocated)
            ->allocated_from_supply_id->toBe($supply->id);
        expect((float) $requirement->allocated_quantity)->toBe(10.0);
        expect((float) $supply->fresh()->allocated_quantity)->toBe(10.0);
    });

    it('allocates partial quantity', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $requirement = ProductionIngredientRequirement::factory()->create([
            'required_quantity' => 20.0,
            'status' => RequirementStatus::Received,
        ]);

        $this->service->allocate($requirement, $supply, 15.0);

        $requirement = $requirement->fresh();
        expect($requirement->status)->toBe(RequirementStatus::Received);
        expect((float) $requirement->allocated_quantity)->toBe(15.0);
        expect((float) $supply->fresh()->allocated_quantity)->toBe(15.0);
    });

    it('allows allocation even when computed available is insufficient if stock is marked in stock', function () {
        $supply = Supply::factory()->inStock(10.0)->create();
        $requirement = ProductionIngredientRequirement::factory()->create([
            'required_quantity' => 20.0,
            'status' => RequirementStatus::Received,
        ]);

        $this->service->allocate($requirement, $supply, 20.0);

        expect((float) $requirement->fresh()->allocated_quantity)->toBe(20.0)
            ->and($requirement->fresh()->status)->toBe(RequirementStatus::Allocated);
    });

    it('throws when supply is not in stock', function () {
        $supply = Supply::factory()->outOfStock()->create();
        $requirement = ProductionIngredientRequirement::factory()->create([
            'required_quantity' => 10.0,
            'status' => RequirementStatus::Received,
        ]);

        $this->service->allocate($requirement, $supply, 10.0);
    })->throws(\InvalidArgumentException::class, 'Supply is not in stock');

    it('throws when requirement is already allocated', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $requirement = ProductionIngredientRequirement::factory()->allocated()->create([
            'required_quantity' => 10.0,
        ]);

        $this->service->allocate($requirement, $supply, 10.0);
    })->throws(\InvalidArgumentException::class, 'Requirement is already allocated');

    it('throws when requirement is fulfilled by masterbatch', function () {
        $masterbatch = Production::factory()->create();
        $supply = Supply::factory()->inStock(50.0)->create();
        $requirement = ProductionIngredientRequirement::factory()
            ->fulfilledByMasterbatch($masterbatch)
            ->create(['required_quantity' => 10.0]);

        $this->service->allocate($requirement, $supply, 10.0);
    })->throws(\InvalidArgumentException::class, 'Cannot allocate requirement fulfilled by masterbatch');

    it('adds to existing allocation', function () {
        $supply = Supply::factory()->inStock(50.0)->create();
        $requirement = ProductionIngredientRequirement::factory()->create([
            'required_quantity' => 20.0,
            'allocated_quantity' => 5.0,
            'status' => RequirementStatus::Received,
        ]);

        $this->service->allocate($requirement, $supply, 10.0);

        $requirement = $requirement->fresh();
        expect($requirement->status)->toBe(RequirementStatus::Received);
        expect((float) $requirement->allocated_quantity)->toBe(15.0);
    });
});

describe('deallocate', function () {
    it('deallocates from requirement', function () {
        $supply = Supply::factory()->create(['allocated_quantity' => 10.0]);
        $requirement = ProductionIngredientRequirement::factory()->create([
            'allocated_quantity' => 10.0,
            'allocated_from_supply_id' => $supply->id,
            'status' => RequirementStatus::Allocated,
        ]);

        $this->service->deallocate($requirement, 10.0);

        $requirement = $requirement->fresh();
        expect($requirement)
            ->status->toBe(RequirementStatus::Received)
            ->allocated_from_supply_id->toBeNull();
        expect((float) $requirement->allocated_quantity)->toBe(0.0);
        expect((float) $supply->fresh()->allocated_quantity)->toBe(0.0);
    });

    it('deallocates partial quantity', function () {
        $supply = Supply::factory()->create(['allocated_quantity' => 20.0]);
        $requirement = ProductionIngredientRequirement::factory()->create([
            'allocated_quantity' => 20.0,
            'allocated_from_supply_id' => $supply->id,
            'status' => RequirementStatus::Allocated,
        ]);

        $this->service->deallocate($requirement, 10.0);

        $requirement = $requirement->fresh();
        expect($requirement->status)->toBe(RequirementStatus::Allocated);
        expect((float) $requirement->allocated_quantity)->toBe(10.0);
        expect((float) $supply->fresh()->allocated_quantity)->toBe(10.0);
    });

    it('throws when deallocating more than allocated', function () {
        $supply = Supply::factory()->create(['allocated_quantity' => 5.0]);
        $requirement = ProductionIngredientRequirement::factory()->create([
            'allocated_quantity' => 5.0,
            'allocated_from_supply_id' => $supply->id,
            'status' => RequirementStatus::Allocated,
        ]);

        $this->service->deallocate($requirement, 10.0);
    })->throws(\InvalidArgumentException::class, 'Cannot deallocate more than allocated');
});

describe('getAvailableSupplies', function () {
    it('returns supplies for ingredient ordered by expiry', function () {
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);

        $supply1 = Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity_in' => 20.0,
            'quantity_out' => 0,
            'allocated_quantity' => 5.0,
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

    it('excludes out of stock supplies', function () {
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);

        Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'is_in_stock' => false,
        ]);

        $available = $this->service->getAvailableSupplies($ingredient);

        expect($available)->toBeEmpty();
    });

    it('includes fully allocated supplies when marked in stock', function () {
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);

        $supply = Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity_in' => 20.0,
            'quantity_out' => 0,
            'allocated_quantity' => 20.0,
            'is_in_stock' => true,
        ]);

        $available = $this->service->getAvailableSupplies($ingredient);

        expect($available)->toHaveCount(1)
            ->and($available->first()->id)->toBe($supply->id);
    });
});

describe('checkAvailability', function () {
    it('returns true when enough available', function () {
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);

        Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity_in' => 50.0,
            'quantity_out' => 0,
            'allocated_quantity' => 10.0,
            'is_in_stock' => true,
        ]);

        expect($this->service->checkAvailability($ingredient, 30.0))->toBeTrue();
    });

    it('returns false when not enough available', function () {
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);

        Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity_in' => 20.0,
            'quantity_out' => 0,
            'allocated_quantity' => 5.0,
            'is_in_stock' => true,
        ]);

        expect($this->service->checkAvailability($ingredient, 20.0))->toBeFalse();
    });

    it('returns false when no supplies', function () {
        $ingredient = Ingredient::factory()->create();

        expect($this->service->checkAvailability($ingredient, 10.0))->toBeFalse();
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
