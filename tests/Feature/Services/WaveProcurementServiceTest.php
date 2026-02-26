<?php

use App\Enums\ProductionStatus;
use App\Enums\RequirementStatus;
use App\Enums\WaveStatus;
use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Production;
use App\Models\Production\ProductionIngredientRequirement;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Services\Production\WaveProcurementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function waveProcurementService(): WaveProcurementService
{
    return app(WaveProcurementService::class);
}

describe('aggregateRequirements', function () {
    it('aggregates requirements by ingredient across wave productions', function () {
        $wave = ProductionWave::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $production1 = Production::factory()->create(['production_wave_id' => $wave->id]);
        $production2 = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production1->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 10.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production2->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 15.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        $aggregated = waveProcurementService()->aggregateRequirements($wave);

        expect($aggregated)->toHaveCount(1)
            ->and($aggregated->first()->ingredient_id)->toBe($ingredient->id)
            ->and((float) $aggregated->first()->total_quantity)->toBe(25.0);
    });

    it('groups by ingredient and supplier listing', function () {
        $wave = ProductionWave::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $supplier = Supplier::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 20.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        $aggregated = waveProcurementService()->aggregateRequirements($wave);

        expect($aggregated->first()->supplier_listing_id)->toBe($listing->id);
    });

    it('excludes requirements fulfilled by masterbatch', function () {
        $wave = ProductionWave::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $masterbatch = Production::factory()->create();

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 10.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        ProductionIngredientRequirement::factory()
            ->fulfilledByMasterbatch($masterbatch)
            ->create([
                'production_id' => $production->id,
                'production_wave_id' => $wave->id,
                'ingredient_id' => $ingredient->id,
                'required_quantity' => 15.0,
            ]);

        $aggregated = waveProcurementService()->aggregateRequirements($wave);

        expect($aggregated)->toHaveCount(1)
            ->and((float) $aggregated->first()->total_quantity)->toBe(10.0);
    });

    it('excludes already allocated requirements', function () {
        $wave = ProductionWave::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 10.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        ProductionIngredientRequirement::factory()->allocated()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 15.0,
        ]);

        $aggregated = waveProcurementService()->aggregateRequirements($wave);

        expect($aggregated)->toHaveCount(1)
            ->and((float) $aggregated->first()->total_quantity)->toBe(10.0);
    });

    it('ignores requirements from cancelled productions', function () {
        $wave = ProductionWave::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $activeProduction = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'status' => ProductionStatus::Planned,
        ]);

        $cancelledProduction = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'status' => ProductionStatus::Cancelled,
        ]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $activeProduction->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 10.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $cancelledProduction->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 15.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        $aggregated = waveProcurementService()->aggregateRequirements($wave);

        expect($aggregated)->toHaveCount(1)
            ->and((float) $aggregated->first()->total_quantity)->toBe(10.0);
    });
});

describe('generatePurchaseOrders', function () {
    it('creates purchase orders grouped by supplier', function () {
        $wave = ProductionWave::factory()->create(['status' => WaveStatus::Approved]);
        $supplier1 = Supplier::factory()->create();
        $supplier2 = Supplier::factory()->create();

        $ingredient1 = Ingredient::factory()->create();
        $ingredient2 = Ingredient::factory()->create();

        $listing1 = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient1->id,
            'supplier_id' => $supplier1->id,
            'unit_weight' => 25.0,
            'price' => 10.00,
        ]);

        $listing2 = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient2->id,
            'supplier_id' => $supplier2->id,
            'unit_weight' => 10.0,
            'price' => 15.00,
        ]);

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient1->id,
            'supplier_listing_id' => $listing1->id,
            'required_quantity' => 50.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient2->id,
            'supplier_listing_id' => $listing2->id,
            'required_quantity' => 30.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        $orders = waveProcurementService()->generatePurchaseOrders($wave);

        expect($orders)->toHaveCount(2);

        $order1 = $orders->first(fn ($o) => $o->supplier_id === $supplier1->id);
        $order2 = $orders->first(fn ($o) => $o->supplier_id === $supplier2->id);

        expect($order1)->not->toBeNull()
            ->and($order1->supplier_order_items)->toHaveCount(1)
            ->and($order2)->not->toBeNull()
            ->and($order2->supplier_order_items)->toHaveCount(1);
    });

    it('marks requirements as ordered', function () {
        $wave = ProductionWave::factory()->create(['status' => WaveStatus::Approved]);
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        $requirement = ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 50.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        waveProcurementService()->generatePurchaseOrders($wave);

        expect($requirement->fresh()->status)->toBe(RequirementStatus::Ordered);
    });

    it('throws when wave is not approved', function () {
        $wave = ProductionWave::factory()->create(['status' => WaveStatus::Draft]);

        waveProcurementService()->generatePurchaseOrders($wave);
    })->throws(\InvalidArgumentException::class, 'Wave must be approved to generate purchase orders');

    it('skips requirements without supplier listing', function () {
        $wave = ProductionWave::factory()->create(['status' => WaveStatus::Approved]);
        $ingredient = Ingredient::factory()->create();

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => null,
            'required_quantity' => 50.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        $orders = waveProcurementService()->generatePurchaseOrders($wave);

        expect($orders)->toBeEmpty();
    });

    it('orders only not ordered quantities when some requirements are already ordered', function () {
        $wave = ProductionWave::factory()->create(['status' => WaveStatus::Approved]);
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'unit_weight' => 25.0,
            'price' => 12.50,
        ]);

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 40.0,
            'status' => RequirementStatus::Ordered,
        ]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 10.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        $orders = waveProcurementService()->generatePurchaseOrders($wave);

        expect($orders)->toHaveCount(1)
            ->and((float) $orders->first()->supplier_order_items->first()->quantity)->toBe(10.0);
    });

    it('does not create orders when everything is already ordered', function () {
        $wave = ProductionWave::factory()->create(['status' => WaveStatus::Approved]);
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 15.0,
            'status' => RequirementStatus::Ordered,
        ]);

        $orders = waveProcurementService()->generatePurchaseOrders($wave);

        expect($orders)->toBeEmpty();
    });
});

describe('getPlanningList', function () {
    it('generates missing wave requirements from productions before building plan', function () {
        $wave = ProductionWave::factory()->create();
        $ingredient = Ingredient::factory()->create([
            'price' => 7.5,
        ]);
        $formula = Formula::factory()->create();

        FormulaItem::factory()->forFormula($formula)
            ->withIngredient($ingredient)
            ->percentage(50)
            ->create();

        $production = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'formula_id' => $formula->id,
            'planned_quantity' => 20,
        ]);

        expect($production->ingredientRequirements()->count())->toBe(0);

        $line = waveProcurementService()->getPlanningList($wave)->first();

        expect($line)->not->toBeNull()
            ->and($line->ingredient_id)->toBe($ingredient->id)
            ->and((float) $line->to_order_quantity)->toBe(10.0);
    });

    it('returns advisory planning quantities for a wave', function () {
        $wave = ProductionWave::factory()->create();
        $supplier = Supplier::factory()->create(['name' => 'Aroma Supply']);
        $ingredient = Ingredient::factory()->create([
            'name' => 'Lavender Oil',
            'price' => 8.50,
        ]);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'unit_weight' => 20.0,
            'price' => 11.75,
        ]);

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 30.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 10.0,
            'status' => RequirementStatus::Ordered,
        ]);

        $listing->supplies()->create([
            'order_ref' => 'PO-001',
            'batch_number' => 'BATCH-001',
            'initial_quantity' => 15.0,
            'quantity_in' => 15.0,
            'quantity_out' => 0,
            'allocated_quantity' => 0,
            'unit_price' => 8.50,
            'expiry_date' => now()->addYear(),
            'delivery_date' => now(),
            'is_in_stock' => true,
        ]);

        $line = waveProcurementService()->getPlanningList($wave)->first();

        expect($line->ingredient_name)->toBe('Lavender Oil')
            ->and((float) $line->not_ordered_quantity)->toBe(30.0)
            ->and((float) $line->ordered_quantity)->toBe(10.0)
            ->and((float) $line->to_order_quantity)->toBe(30.0)
            ->and((float) $line->stock_advisory)->toBe(15.0)
            ->and((float) $line->advisory_shortage)->toBe(15.0)
            ->and((float) $line->ingredient_price)->toBe(8.5)
            ->and((float) $line->estimated_cost)->toBe(255.0);
    });
});

describe('getProcurementSummary', function () {
    it('returns summary of requirements by status', function () {
        $wave = ProductionWave::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionIngredientRequirement::factory()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 10.0,
            'status' => RequirementStatus::NotOrdered,
        ]);

        ProductionIngredientRequirement::factory()->ordered()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 15.0,
        ]);

        ProductionIngredientRequirement::factory()->received()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 20.0,
        ]);

        ProductionIngredientRequirement::factory()->allocated()->create([
            'production_id' => $production->id,
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 25.0,
        ]);

        $summary = waveProcurementService()->getProcurementSummary($wave);

        expect($summary)
            ->toHaveKey('not_ordered', 1)
            ->toHaveKey('ordered', 1)
            ->toHaveKey('received', 1)
            ->toHaveKey('allocated', 1)
            ->toHaveKey('total', 4);
    });
});
