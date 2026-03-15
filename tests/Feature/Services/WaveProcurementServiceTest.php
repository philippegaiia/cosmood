<?php

use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Enums\WaveStatus;
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
use App\Services\Production\WaveProcurementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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

    it('keeps received quantities visible in planning and summary', function () {
        $wave = ProductionWave::factory()->create(['status' => WaveStatus::Approved]);
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile tournesol']);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        $production = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'status' => ProductionStatus::Planned,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 18.4,
            'procurement_status' => ProcurementStatus::Received,
        ]);

        $line = waveProcurementService()->getPlanningList($wave)->first();
        $summary = waveProcurementService()->getPlanningSummary($wave);

        expect($line->ingredient_name)->toBe('Huile tournesol')
            ->and((float) $line->required_remaining_quantity)->toBe(18.4)
            ->and((float) $line->received_quantity)->toBe(18.4)
            ->and((float) $line->covered_quantity)->toBe(18.4)
            ->and((float) $line->to_order_quantity)->toBe(0.0)
            ->and((float) ($summary['received_total'] ?? 0))->toBe(18.4)
            ->and((float) ($summary['covered_total'] ?? 0))->toBe(18.4);
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

describe('getActiveWavesPlanningList', function () {
    it('consolidates active waves by ingredient with strict to-order totals', function () {
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile Tournesol']);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'unit_weight' => 19.0,
        ]);

        $waveA = ProductionWave::factory()->create([
            'status' => WaveStatus::Approved,
            'planned_start_date' => '2026-03-10',
        ]);

        $waveB = ProductionWave::factory()->create([
            'status' => WaveStatus::InProgress,
            'planned_start_date' => '2026-03-15',
        ]);

        $draftWave = ProductionWave::factory()->create([
            'status' => WaveStatus::Draft,
            'planned_start_date' => '2026-03-20',
        ]);

        $product = Product::factory()->create();
        $formula = Formula::query()->create([
            'name' => 'Formula active waves procurement',
            'slug' => Str::slug('formula-active-waves-'.Str::uuid()),
            'code' => 'FRM-'.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $createProduction = function (ProductionWave $wave, string $batchNumber, string $productionDate) use ($product, $formula): Production {
            return Production::withoutEvents(function () use ($wave, $batchNumber, $productionDate, $product, $formula): Production {
                return Production::query()->create([
                    'production_wave_id' => $wave->id,
                    'product_id' => $product->id,
                    'formula_id' => $formula->id,
                    'batch_number' => $batchNumber,
                    'slug' => Str::slug($batchNumber.'-'.Str::uuid()),
                    'status' => ProductionStatus::Planned,
                    'sizing_mode' => SizingMode::OilWeight,
                    'planned_quantity' => 10,
                    'expected_units' => 100,
                    'production_date' => $productionDate,
                    'ready_date' => now()->addDays(2)->toDateString(),
                    'organic' => true,
                ]);
            });
        };

        $productionA = $createProduction($waveA, 'T97001', '2026-03-10');
        $productionB = $createProduction($waveB, 'T97002', '2026-03-16');
        $productionDraft = $createProduction($draftWave, 'T97003', '2026-03-20');

        ProductionItem::factory()->create([
            'production_id' => $productionA->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 100.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $productionB->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 60.0,
            'procurement_status' => ProcurementStatus::Ordered,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $productionDraft->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 80.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $listing->supplies()->create([
            'order_ref' => 'PO-STOCK-001',
            'batch_number' => 'LOT-STOCK-001',
            'initial_quantity' => 40.0,
            'quantity_in' => 40.0,
            'quantity_out' => 0.0,
            'allocated_quantity' => 0.0,
            'unit_price' => 4.2,
            'expiry_date' => now()->addMonths(6),
            'delivery_date' => now(),
            'is_in_stock' => true,
        ]);

        $order = SupplierOrder::factory()->confirmed()->create([
            'supplier_id' => $supplier->id,
        ]);

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $order->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 10,
            'unit_weight' => 19,
            'moved_to_stock_at' => null,
        ]);

        $line = waveProcurementService()->getActiveWavesPlanningList()->first();

        expect($line->ingredient_name)->toBe('Huile Tournesol')
            ->and((float) $line->required_remaining_quantity)->toBe(160.0)
            ->and((float) $line->ordered_quantity)->toBe(60.0)
            ->and((float) $line->to_order_quantity)->toBe(100.0)
            ->and((float) $line->committed_open_order_quantity)->toBe(0.0)
            ->and((float) $line->priority_provisional_quantity)->toBe(100.0)
            ->and((float) $line->to_secure_quantity)->toBe(0.0)
            ->and((float) $line->stock_advisory)->toBe(40.0)
            ->and((float) $line->open_order_quantity)->toBe(190.0)
            ->and((float) $line->shared_provisional_quantity)->toBe(190.0)
            ->and((float) $line->advisory_shortage)->toBe(0.0)
            ->and($line->waves_count)->toBe(2)
            ->and($line->waves)->toHaveCount(2);
    });

    it('keeps shared provisional pool after origin-wave commitment and prioritizes by need date', function () {
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile Tournesol']);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'unit_weight' => 46,
        ]);

        $waveA = ProductionWave::factory()->create([
            'status' => WaveStatus::Approved,
            'planned_start_date' => '2026-03-10',
        ]);

        $waveB = ProductionWave::factory()->create([
            'status' => WaveStatus::Approved,
            'planned_start_date' => '2026-03-18',
        ]);

        $product = Product::factory()->create();
        $formula = Formula::query()->create([
            'name' => 'Formula commitment pool',
            'slug' => Str::slug('formula-commitment-pool-'.Str::uuid()),
            'code' => 'FRM-'.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $createProduction = function (ProductionWave $wave, string $batchNumber, string $productionDate) use ($product, $formula): Production {
            return Production::withoutEvents(function () use ($wave, $batchNumber, $productionDate, $product, $formula): Production {
                return Production::query()->create([
                    'production_wave_id' => $wave->id,
                    'product_id' => $product->id,
                    'formula_id' => $formula->id,
                    'batch_number' => $batchNumber,
                    'slug' => Str::slug($batchNumber.'-'.Str::uuid()),
                    'status' => ProductionStatus::Planned,
                    'sizing_mode' => SizingMode::OilWeight,
                    'planned_quantity' => 10,
                    'expected_units' => 100,
                    'production_date' => $productionDate,
                    'ready_date' => now()->addDays(2)->toDateString(),
                    'organic' => true,
                ]);
            });
        };

        $productionA = $createProduction($waveA, 'T97101', '2026-03-10');
        $productionB = $createProduction($waveB, 'T97102', '2026-03-19');

        ProductionItem::factory()->create([
            'production_id' => $productionA->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 100.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $productionB->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 300.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $order = SupplierOrder::factory()->confirmed()->create([
            'supplier_id' => $supplier->id,
            'production_wave_id' => $waveA->id,
        ]);

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $order->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 20,
            'unit_weight' => 46,
            'committed_quantity_kg' => 100,
            'moved_to_stock_at' => null,
        ]);

        $waveALine = waveProcurementService()->getPlanningList($waveA)->first();
        $waveBLine = waveProcurementService()->getPlanningList($waveB)->first();

        expect((float) $waveALine->committed_open_order_quantity)->toBe(100.0)
            ->and((float) $waveALine->shared_provisional_quantity)->toBe(820.0)
            ->and((float) $waveALine->priority_provisional_quantity)->toBe(0.0)
            ->and((float) $waveALine->to_secure_quantity)->toBe(0.0)
            ->and((float) $waveBLine->committed_open_order_quantity)->toBe(0.0)
            ->and((float) $waveBLine->shared_provisional_quantity)->toBe(820.0)
            ->and((float) $waveBLine->priority_provisional_quantity)->toBe(300.0)
            ->and((float) $waveBLine->to_secure_quantity)->toBe(0.0);
    });

    it('separates firm orders from draft orders in planning context', function () {
        $wave = ProductionWave::factory()->create([
            'status' => WaveStatus::Approved,
            'planned_start_date' => '2026-03-10',
        ]);

        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile coco']);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'unit_weight' => 20,
        ]);

        $production = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'status' => ProductionStatus::Planned,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 80.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $firmOrder = SupplierOrder::factory()->passed()->create([
            'supplier_id' => $supplier->id,
            'production_wave_id' => $wave->id,
        ]);

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $firmOrder->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 2,
            'unit_weight' => 20,
            'moved_to_stock_at' => null,
        ]);

        $draftOrder = SupplierOrder::factory()->draft()->create([
            'supplier_id' => $supplier->id,
            'production_wave_id' => $wave->id,
        ]);

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $draftOrder->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 1,
            'unit_weight' => 20,
            'moved_to_stock_at' => null,
        ]);

        $line = waveProcurementService()->getPlanningList($wave)->first();
        $summary = waveProcurementService()->getPlanningSummary($wave);

        expect((float) $line->firm_open_order_quantity)->toBe(40.0)
            ->and((float) $line->draft_open_order_quantity)->toBe(20.0)
            ->and((float) ($summary['firm_order_total'] ?? 0))->toBe(40.0)
            ->and((float) ($summary['draft_order_total'] ?? 0))->toBe(20.0);
    });
});
