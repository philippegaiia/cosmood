<?php

use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Enums\WaveStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
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
        $listing = SupplierListing::factory()->create(['ingredient_id' => $ingredient->id]);

        $production1 = Production::factory()->create(['production_wave_id' => $wave->id]);
        $production2 = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionItem::factory()->create([
            'production_id' => $production1->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 10.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production2->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 15.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
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

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 20.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $aggregated = waveProcurementService()->aggregateRequirements($wave);

        expect($aggregated->first()->supplier_listing_id)->toBe($listing->id);
    });

    it('excludes items from masterbatch-replaced phases', function () {
        $wave = ProductionWave::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $masterbatch = Production::factory()->masterbatch()->create([
            'replaces_phase' => Phases::Saponification->value,
        ]);

        $production = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'masterbatch_lot_id' => $masterbatch->id,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'phase' => Phases::Saponification->value,
            'required_quantity' => 10.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $aggregated = waveProcurementService()->aggregateRequirements($wave);

        expect($aggregated)->toHaveCount(0);
    });

    it('ignores items from cancelled productions', function () {
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

        ProductionItem::factory()->create([
            'production_id' => $activeProduction->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 10.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $cancelledProduction->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 15.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
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

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient1->id,
            'supplier_listing_id' => $listing1->id,
            'required_quantity' => 50.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient2->id,
            'supplier_listing_id' => $listing2->id,
            'required_quantity' => 30.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
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

    it('marks items as ordered', function () {
        $wave = ProductionWave::factory()->create(['status' => WaveStatus::Approved]);
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 50.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        waveProcurementService()->generatePurchaseOrders($wave);

        expect($item->fresh()->procurement_status)->toBe(ProcurementStatus::Ordered);
    });

    it('throws when wave is not approved', function () {
        $wave = ProductionWave::factory()->create(['status' => WaveStatus::Draft]);

        waveProcurementService()->generatePurchaseOrders($wave);
    })->throws(\InvalidArgumentException::class, 'Wave must be approved to generate purchase orders');

    it('skips items without supplier listing', function () {
        $wave = ProductionWave::factory()->create(['status' => WaveStatus::Approved]);
        $ingredient = Ingredient::factory()->create();

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => null,
            'required_quantity' => 50.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $orders = waveProcurementService()->generatePurchaseOrders($wave);

        expect($orders)->toBeEmpty();
    });

    it('orders only not ordered quantities when some items are already ordered', function () {
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

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 40.0,
            'procurement_status' => ProcurementStatus::Ordered,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 10.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
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

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 15.0,
            'procurement_status' => ProcurementStatus::Ordered,
        ]);

        $orders = waveProcurementService()->generatePurchaseOrders($wave);

        expect($orders)->toBeEmpty();
    });
});

describe('getPlanningList', function () {
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

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 30.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 10.0,
            'procurement_status' => ProcurementStatus::Ordered,
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
    it('returns summary of items by status', function () {
        $wave = ProductionWave::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 10.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 15.0,
            'procurement_status' => ProcurementStatus::Ordered,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 20.0,
            'procurement_status' => ProcurementStatus::Received,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 25.0,
            'procurement_status' => ProcurementStatus::Confirmed,
        ]);

        $summary = waveProcurementService()->getProcurementSummary($wave);

        expect($summary)
            ->toHaveKey('not_ordered', 1)
            ->toHaveKey('ordered', 2)
            ->toHaveKey('received', 1)
            ->toHaveKey('total', 4);
    });
});
