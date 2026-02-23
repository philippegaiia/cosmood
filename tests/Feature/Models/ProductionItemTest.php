<?php

use App\Enums\Phases;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\Supply;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ProductionItem Model', function () {
    it('can be created', function () {
        $production = Production::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);

        $item = ProductionItem::create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'percentage_of_oils' => 12.5,
            'phase' => 'saponification',
            'organic' => true,
            'is_supplied' => false,
            'sort' => 1,
        ]);

        expect($item)->toBeInstanceOf(ProductionItem::class)
            ->and($item->production->id)->toBe($production->id)
            ->and($item->supplierListing->id)->toBe($listing->id)
            ->and($item->production_task->id)->toBe($listing->id);
    });

    it('casts booleans', function () {
        $production = Production::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);

        $item = ProductionItem::create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'percentage_of_oils' => 5,
            'phase' => 'additives',
            'organic' => 1,
            'is_supplied' => 0,
            'sort' => 2,
        ]);

        expect($item->organic)->toBeTrue()
            ->and($item->is_supplied)->toBeFalse();
    });

    it('can be created without supplier listing and supply', function () {
        $production = Production::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $item = ProductionItem::create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => null,
            'supply_id' => null,
            'supply_batch_number' => null,
            'percentage_of_oils' => 10,
            'phase' => 'additives',
            'organic' => true,
            'is_supplied' => false,
            'sort' => 3,
        ]);

        expect($item->supplier_listing_id)->toBeNull()
            ->and($item->supply_id)->toBeNull();
    });

    it('can store selected supply batch for traceability', function () {
        $production = Production::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);
        $supply = Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'batch_number' => 'LOT-TRACE-001',
        ]);

        $item = ProductionItem::create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'supply_id' => $supply->id,
            'supply_batch_number' => $supply->batch_number,
            'percentage_of_oils' => 20,
            'phase' => 'saponification',
            'organic' => true,
            'is_supplied' => true,
            'sort' => 4,
        ]);

        expect($item->supply->id)->toBe($supply->id)
            ->and($item->supply_batch_number)->toBe('LOT-TRACE-001');
    });

    it('marks item as supplied when a supply is assigned', function () {
        $production = Production::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);
        $supply = Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'supply_id' => $supply->id,
            'is_supplied' => false,
        ]);

        expect($item->fresh()->is_supplied)->toBeTrue();
    });

    it('resolves phase label from numeric phase value', function () {
        $item = ProductionItem::factory()->create([
            'phase' => Phases::Additives->value,
        ]);

        expect($item->getPhaseLabel())->toBe('Additifs');
    });

    it('calculates estimated item cost from supply price first', function () {
        $production = Production::factory()->create([
            'planned_quantity' => 100,
        ]);
        $ingredient = Ingredient::factory()->create([
            'price' => 5.00,
        ]);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'price' => 7.00,
        ]);
        $supply = Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'unit_price' => 9.00,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'supply_id' => $supply->id,
            'percentage_of_oils' => 10,
        ]);

        expect($item->getCalculatedQuantityKg())->toBe(10.0)
            ->and($item->getReferenceUnitPrice())->toBe(9.0)
            ->and($item->getEstimatedCost())->toBe(90.0);
    });

    it('falls back to supplier listing then ingredient price for estimated cost', function () {
        $production = Production::factory()->create([
            'planned_quantity' => 80,
        ]);
        $ingredient = Ingredient::factory()->create([
            'price' => 4.50,
        ]);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'price' => 6.25,
        ]);

        $itemWithListing = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'supply_id' => null,
            'percentage_of_oils' => 5,
        ]);

        $itemWithIngredientPrice = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => null,
            'supply_id' => null,
            'percentage_of_oils' => 5,
        ]);

        expect($itemWithListing->getReferenceUnitPrice())->toBe(6.25)
            ->and($itemWithListing->getEstimatedCost())->toBe(25.0)
            ->and($itemWithIngredientPrice->getReferenceUnitPrice())->toBe(4.5)
            ->and($itemWithIngredientPrice->getEstimatedCost())->toBe(18.0);
    });
});
