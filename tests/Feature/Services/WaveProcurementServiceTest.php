<?php

use App\Enums\OrderStatus;
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
use App\Models\Production\ProductionWaveStockDecision;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use App\Models\User;
use App\Services\InventoryMovementService;
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
        $wave = ProductionWave::factory()->create([
            'planned_start_date' => '2026-03-15',
        ]);
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
            ->and((float) $line->total_wave_requirement)->toBe(40.0)
            ->and((float) $line->allocated_quantity)->toBe(0.0)
            ->and((float) $line->remaining_requirement)->toBe(40.0)
            ->and((float) $line->available_stock)->toBe(15.0)
            ->and((float) $line->reserved_stock_quantity)->toBe(0.0)
            ->and((float) $line->planned_stock_quantity)->toBe(15.0)
            ->and((float) $line->wave_committed_open_orders)->toBe(0.0)
            ->and((float) $line->open_orders_not_committed)->toBe(0.0)
            ->and((float) $line->remaining_to_secure)->toBe(25.0)
            ->and((float) $line->remaining_to_order)->toBe(25.0)
            ->and((float) $line->not_ordered_quantity)->toBe(30.0)
            ->and((float) $line->ordered_quantity)->toBe(10.0)
            ->and((float) $line->to_order_quantity)->toBe(30.0)
            ->and((float) $line->stock_advisory)->toBe(15.0)
            ->and((float) $line->advisory_shortage)->toBe(15.0)
            ->and($line->need_date)->toBe('2026-03-08')
            ->and((float) $line->ingredient_price)->toBe(8.5)
            ->and((float) $line->estimated_cost)->toBe(212.5);
    });

    it('reduces mobilizable stock and increases remaining to order when a reserve is kept for the wave', function () {
        $wave = ProductionWave::factory()->create([
            'planned_start_date' => '2026-03-15',
        ]);
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile tournesol']);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        $production = Production::factory()->create(['production_wave_id' => $wave->id]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 80.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $listing->supplies()->create([
            'order_ref' => 'PO-RESERVE-001',
            'batch_number' => 'SUN-001',
            'initial_quantity' => 100.0,
            'quantity_in' => 100.0,
            'quantity_out' => 0,
            'allocated_quantity' => 0,
            'unit_price' => 3.40,
            'expiry_date' => now()->addYear(),
            'delivery_date' => now(),
            'is_in_stock' => true,
        ]);

        ProductionWaveStockDecision::factory()->create([
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'reserved_quantity' => 30.0,
        ]);

        $line = waveProcurementService()->getPlanningList($wave)->first();
        $summary = waveProcurementService()->getPlanningSummary($wave);

        expect((float) $line->available_stock)->toBe(100.0)
            ->and((float) $line->reserved_stock_quantity)->toBe(30.0)
            ->and((float) $line->planned_stock_quantity)->toBe(70.0)
            ->and((float) $line->remaining_to_secure)->toBe(10.0)
            ->and((float) $line->remaining_to_order)->toBe(10.0)
            ->and((float) ($summary['reserved_stock_total'] ?? 0))->toBe(30.0)
            ->and((float) ($summary['planned_stock_total'] ?? 0))->toBe(70.0)
            ->and((float) ($summary['remaining_to_order_total'] ?? 0))->toBe(10.0);
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
            ->and((float) $line->total_wave_requirement)->toBe(18.4)
            ->and((float) $line->allocated_quantity)->toBe(0.0)
            ->and((float) $line->remaining_requirement)->toBe(18.4)
            ->and((float) $line->required_remaining_quantity)->toBe(18.4)
            ->and((float) $line->received_quantity)->toBe(18.4)
            ->and((float) $line->covered_quantity)->toBe(18.4)
            ->and((float) $line->to_order_quantity)->toBe(0.0)
            ->and((float) ($summary['total_requirement_total'] ?? 0))->toBe(18.4)
            ->and((float) ($summary['remaining_requirement_total'] ?? 0))->toBe(18.4)
            ->and((float) ($summary['received_total'] ?? 0))->toBe(18.4)
            ->and((float) ($summary['covered_total'] ?? 0))->toBe(18.4);
    });

    it('keeps unit-based wave orders visible after reception and formats them in units', function () {
        $wave = ProductionWave::factory()->create([
            'planned_start_date' => '2026-03-20',
        ]);
        $supplier = Supplier::factory()->create();
        $user = User::factory()->create();
        $ingredient = Ingredient::factory()->unitBased()->create([
            'name' => 'Boite Margo',
        ]);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'unit_of_measure' => 'u',
            'unit_weight' => 24,
        ]);

        $production = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'status' => ProductionStatus::Planned,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 72,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $order = SupplierOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'production_wave_id' => $wave->id,
            'order_status' => OrderStatus::Checked,
            'delivery_date' => now()->toDateString(),
            'order_ref' => 'PO-U-001',
        ]);

        $orderItem = SupplierOrderItem::factory()->create([
            'supplier_order_id' => $order->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 3,
            'unit_weight' => 24,
            'committed_quantity_kg' => 0,
            'unit_price' => 1.5,
        ]);

        app(InventoryMovementService::class)->receiveOrderItemIntoStock(
            $orderItem,
            (string) $order->order_ref,
            (string) $order->delivery_date,
            $user,
        );

        $line = waveProcurementService()->getPlanningList($wave)->first();

        expect($line->ingredient_name)->toBe('Boite Margo')
            ->and($line->display_unit)->toBe('u')
            ->and((float) $line->wave_ordered_quantity)->toBe(72.0)
            ->and((float) $line->wave_received_quantity)->toBe(72.0)
            ->and((float) $line->available_stock)->toBe(72.0)
            ->and((float) $line->remaining_to_order)->toBe(0.0);
    });
});

describe('getCoverageSnapshotForWaves', function () {
    it('returns bulk coverage snapshots for empty and visible non-active waves', function () {
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Beurre de karite']);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        $emptyWave = ProductionWave::factory()->draft()->create([
            'planned_start_date' => '2026-03-20',
        ]);

        $draftWave = ProductionWave::factory()->draft()->create([
            'planned_start_date' => '2026-03-22',
        ]);

        $production = Production::factory()->create([
            'production_wave_id' => $draftWave->id,
            'status' => ProductionStatus::Planned,
            'production_date' => '2026-03-22',
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 10.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        Supply::factory()->inStock(20.0)->create([
            'supplier_listing_id' => $listing->id,
            'is_in_stock' => true,
        ]);

        $snapshots = waveProcurementService()->getCoverageSnapshotForWaves(collect([
            $emptyWave,
            $draftWave,
        ]));

        expect($snapshots)->toHaveCount(2)
            ->and($snapshots->get($emptyWave->id))->toBe([
                'label' => 'Sans besoin',
                'color' => 'gray',
                'tooltip' => 'Aucune production liée.',
            ])
            ->and($snapshots->get($draftWave->id)['label'])->toBe('Partielle')
            ->and($snapshots->get($draftWave->id)['color'])->toBe('warning')
            ->and($snapshots->get($draftWave->id)['tooltip'])->toContain('Besoin total: 10,000 kg');
    });
});

describe('getPlanningListForProduction', function () {
    it('accounts for directly linked supplier orders on standalone productions', function () {
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create([
            'name' => 'Huile Coco',
            'price' => 6.5,
        ]);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'unit_weight' => 1,
        ]);

        $product = Product::factory()->create();
        $formula = Formula::query()->create([
            'name' => 'Formula orphan linked po',
            'slug' => Str::slug('formula-orphan-linked-po-'.Str::uuid()),
            'code' => 'FRM-'.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $production = Production::withoutEvents(function () use ($product, $formula): Production {
            return Production::query()->create([
                'production_wave_id' => null,
                'product_id' => $product->id,
                'formula_id' => $formula->id,
                'batch_number' => 'T97210',
                'slug' => 'batch-orphan-linked-po',
                'status' => ProductionStatus::Confirmed,
                'sizing_mode' => SizingMode::OilWeight,
                'planned_quantity' => 12,
                'expected_units' => 120,
                'production_date' => '2026-03-18',
                'ready_date' => '2026-03-20',
                'organic' => true,
            ]);
        });

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 50.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $listing->supplies()->create([
            'order_ref' => 'PO-STOCK-ORPHAN-001',
            'batch_number' => 'LOT-STOCK-ORPHAN-001',
            'initial_quantity' => 10.0,
            'quantity_in' => 10.0,
            'quantity_out' => 0.0,
            'allocated_quantity' => 0.0,
            'unit_price' => 6.5,
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
            'quantity' => 30,
            'unit_weight' => 1,
            'allocated_to_production_id' => $production->id,
            'allocated_quantity' => 30,
            'moved_to_stock_at' => null,
        ]);

        $line = waveProcurementService()->getPlanningListForProduction($production)->sole();
        $summary = waveProcurementService()->getPlanningSummaryForProduction($production);

        expect($line->ingredient_name)->toBe('Huile Coco')
            ->and((float) $line->total_wave_requirement)->toBe(50.0)
            ->and((float) $line->available_stock)->toBe(10.0)
            ->and((float) $line->planned_stock_quantity)->toBe(10.0)
            ->and((float) $line->wave_ordered_quantity)->toBe(30.0)
            ->and((float) $line->wave_open_order_quantity)->toBe(30.0)
            ->and((float) $line->wave_received_quantity)->toBe(0.0)
            ->and((float) $line->wave_committed_open_orders)->toBe(30.0)
            ->and((float) $line->remaining_after_linked_orders)->toBe(20.0)
            ->and((float) $line->remaining_to_secure)->toBe(10.0)
            ->and((float) $line->remaining_to_order)->toBe(10.0)
            ->and((float) ($summary['remaining_to_order_total'] ?? 0))->toBe(10.0);
    });

    it('keeps open orders linked to cancelled orphan productions available in the shared pool', function () {
        $wave = ProductionWave::factory()->create([
            'status' => WaveStatus::Approved,
            'planned_start_date' => '2026-03-20',
        ]);
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile Coco']);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'unit_weight' => 1,
        ]);

        $waveProduction = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'status' => ProductionStatus::Planned,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $waveProduction->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 40.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $cancelledProduction = Production::factory()->orphan()->create([
            'status' => ProductionStatus::Cancelled,
            'production_date' => '2026-03-18',
        ]);

        ProductionItem::factory()->create([
            'production_id' => $cancelledProduction->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 30.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $order = SupplierOrder::factory()->confirmed()->create([
            'supplier_id' => $supplier->id,
        ]);

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $order->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 30,
            'unit_weight' => 1,
            'allocated_to_production_id' => $cancelledProduction->id,
            'allocated_quantity' => 30,
            'moved_to_stock_at' => null,
        ]);

        $line = waveProcurementService()->getPlanningList($wave)->sole();

        expect($line->ingredient_name)->toBe('Huile Coco')
            ->and((float) $line->open_orders_not_committed)->toBe(30.0)
            ->and((float) $line->remaining_to_secure)->toBe(40.0)
            ->and((float) $line->remaining_to_order)->toBe(10.0);
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
            ->and((float) $line->total_wave_requirement)->toBe(160.0)
            ->and((float) $line->allocated_quantity)->toBe(0.0)
            ->and((float) $line->remaining_requirement)->toBe(160.0)
            ->and((float) $line->required_remaining_quantity)->toBe(160.0)
            ->and((float) $line->ordered_quantity)->toBe(60.0)
            ->and((float) $line->to_order_quantity)->toBe(100.0)
            ->and((float) $line->available_stock)->toBe(40.0)
            ->and((float) $line->wave_committed_open_orders)->toBe(0.0)
            ->and((float) $line->remaining_to_secure)->toBe(80.0)
            ->and((float) $line->remaining_to_order)->toBe(0.0)
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
            ->and((float) $waveALine->wave_committed_open_orders)->toBe(100.0)
            ->and((float) $waveALine->remaining_to_secure)->toBe(0.0)
            ->and((float) $waveALine->remaining_to_order)->toBe(0.0)
            ->and((float) $waveALine->shared_provisional_quantity)->toBe(820.0)
            ->and((float) $waveALine->open_orders_not_committed)->toBe(0.0)
            ->and((float) $waveALine->priority_provisional_quantity)->toBe(0.0)
            ->and((float) $waveALine->to_secure_quantity)->toBe(0.0)
            ->and((float) $waveBLine->committed_open_order_quantity)->toBe(0.0)
            ->and((float) $waveBLine->wave_committed_open_orders)->toBe(0.0)
            ->and((float) $waveBLine->remaining_to_secure)->toBe(300.0)
            ->and((float) $waveBLine->remaining_to_order)->toBe(0.0)
            ->and((float) $waveBLine->shared_provisional_quantity)->toBe(820.0)
            ->and((float) $waveBLine->open_orders_not_committed)->toBe(820.0)
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
            ->and((float) $line->wave_committed_open_orders)->toBe(0.0)
            ->and((float) $line->open_orders_not_committed)->toBe(0.0)
            ->and((float) $line->remaining_to_secure)->toBe(40.0)
            ->and((float) $line->remaining_to_order)->toBe(40.0)
            ->and((float) $line->draft_open_order_quantity)->toBe(20.0)
            ->and((float) ($summary['remaining_to_order_total'] ?? 0))->toBe(40.0)
            ->and((float) ($summary['firm_order_total'] ?? 0))->toBe(40.0)
            ->and((float) ($summary['draft_order_total'] ?? 0))->toBe(20.0);
    });
});

describe('getOperationalPlanningList', function () {
    it('prioritizes stock and open inbound quantities across waves and standalone productions', function () {
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile Tournesol']);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'unit_weight' => 1,
        ]);

        $wave = ProductionWave::factory()->create([
            'name' => 'Vague Mars',
            'status' => WaveStatus::Draft,
            'planned_start_date' => '2026-03-20',
        ]);

        $product = Product::factory()->create();
        $formula = Formula::query()->create([
            'name' => 'Formula operational planning',
            'slug' => Str::slug('formula-operational-planning-'.Str::uuid()),
            'code' => 'FRM-'.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $waveProduction = Production::withoutEvents(function () use ($wave, $product, $formula): Production {
            return Production::query()->create([
                'production_wave_id' => $wave->id,
                'product_id' => $product->id,
                'formula_id' => $formula->id,
                'batch_number' => 'T97201',
                'slug' => 'batch-wave-operational',
                'status' => ProductionStatus::Planned,
                'sizing_mode' => SizingMode::OilWeight,
                'planned_quantity' => 10,
                'expected_units' => 100,
                'production_date' => '2026-03-20',
                'ready_date' => '2026-03-22',
                'organic' => true,
            ]);
        });

        $standaloneProduction = Production::withoutEvents(function () use ($product, $formula): Production {
            return Production::query()->create([
                'production_wave_id' => null,
                'product_id' => $product->id,
                'formula_id' => $formula->id,
                'batch_number' => 'T97202',
                'slug' => 'batch-standalone-operational',
                'status' => ProductionStatus::Confirmed,
                'sizing_mode' => SizingMode::OilWeight,
                'planned_quantity' => 8,
                'expected_units' => 80,
                'production_date' => '2026-03-16',
                'ready_date' => '2026-03-18',
                'organic' => true,
            ]);
        });

        ProductionItem::factory()->create([
            'production_id' => $waveProduction->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 100.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $standaloneProduction->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 60.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $listing->supplies()->create([
            'order_ref' => 'PO-STOCK-OPER-001',
            'batch_number' => 'LOT-STOCK-OPER-001',
            'initial_quantity' => 40.0,
            'quantity_in' => 40.0,
            'quantity_out' => 0.0,
            'allocated_quantity' => 0.0,
            'unit_price' => 4.2,
            'expiry_date' => now()->addMonths(6),
            'delivery_date' => now(),
            'is_in_stock' => true,
        ]);

        $linkedOrder = SupplierOrder::factory()->confirmed()->create([
            'supplier_id' => $supplier->id,
            'production_wave_id' => $wave->id,
        ]);

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $linkedOrder->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 30,
            'unit_weight' => 1,
            'moved_to_stock_at' => null,
        ]);

        $sharedOrder = SupplierOrder::factory()->confirmed()->create([
            'supplier_id' => $supplier->id,
            'production_wave_id' => null,
        ]);

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $sharedOrder->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 50,
            'unit_weight' => 1,
            'moved_to_stock_at' => null,
        ]);

        $line = waveProcurementService()->getOperationalPlanningList()->first();
        $summary = waveProcurementService()->getOperationalPlanningSummary();

        $productionContext = $line->contexts->firstWhere('context_type', 'production');
        $waveContext = $line->contexts->firstWhere('context_type', 'wave');

        expect($line->ingredient_name)->toBe('Huile Tournesol')
            ->and((float) $line->total_wave_requirement)->toBe(160.0)
            ->and((float) $line->remaining_requirement)->toBe(160.0)
            ->and((float) $line->available_stock)->toBe(40.0)
            ->and((float) $line->wave_ordered_quantity)->toBe(30.0)
            ->and((float) $line->wave_open_order_quantity)->toBe(30.0)
            ->and((float) $line->open_orders_not_committed)->toBe(80.0)
            ->and((float) $line->remaining_to_secure)->toBe(90.0)
            ->and((float) $line->remaining_to_order)->toBe(10.0)
            ->and($line->contexts_count)->toBe(2)
            ->and($line->contexts)->toHaveCount(2)
            ->and($productionContext)->not->toBeNull()
            ->and($waveContext)->not->toBeNull()
            ->and((float) $productionContext->stock_priority_quantity)->toBe(40.0)
            ->and((float) $productionContext->open_orders_priority_quantity)->toBe(20.0)
            ->and((float) $productionContext->remaining_to_order)->toBe(0.0)
            ->and((float) $waveContext->wave_open_order_quantity)->toBe(30.0)
            ->and((float) $waveContext->open_orders_priority_quantity)->toBe(60.0)
            ->and((float) $waveContext->remaining_to_order)->toBe(10.0)
            ->and($summary['remaining_to_order'])->toBe('10,000 kg')
            ->and($summary['ingredients_to_order'])->toBe(1)
            ->and($summary['contexts_count'])->toBe(2);
    });

    it('reduces standalone operational gaps with production-linked supplier orders', function () {
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile Coco']);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'unit_weight' => 1,
        ]);

        $product = Product::factory()->create();
        $formula = Formula::query()->create([
            'name' => 'Formula orphan operational linked po',
            'slug' => Str::slug('formula-orphan-operational-linked-po-'.Str::uuid()),
            'code' => 'FRM-'.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $production = Production::withoutEvents(function () use ($product, $formula): Production {
            return Production::query()->create([
                'production_wave_id' => null,
                'product_id' => $product->id,
                'formula_id' => $formula->id,
                'batch_number' => 'T97211',
                'slug' => 'batch-orphan-operational-linked-po',
                'status' => ProductionStatus::Confirmed,
                'sizing_mode' => SizingMode::OilWeight,
                'planned_quantity' => 12,
                'expected_units' => 120,
                'production_date' => '2026-03-18',
                'ready_date' => '2026-03-20',
                'organic' => true,
            ]);
        });

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 50.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $listing->supplies()->create([
            'order_ref' => 'PO-STOCK-ORPHAN-OPER-001',
            'batch_number' => 'LOT-STOCK-ORPHAN-OPER-001',
            'initial_quantity' => 10.0,
            'quantity_in' => 10.0,
            'quantity_out' => 0.0,
            'allocated_quantity' => 0.0,
            'unit_price' => 6.5,
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
            'quantity' => 30,
            'unit_weight' => 1,
            'allocated_to_production_id' => $production->id,
            'allocated_quantity' => 30,
            'moved_to_stock_at' => null,
        ]);

        $line = waveProcurementService()->getOperationalPlanningList()->sole();
        $summary = waveProcurementService()->getOperationalPlanningSummary();
        $context = $line->contexts->sole();

        expect($line->ingredient_name)->toBe('Huile Coco')
            ->and((float) $line->available_stock)->toBe(10.0)
            ->and((float) $line->wave_ordered_quantity)->toBe(30.0)
            ->and((float) $line->wave_open_order_quantity)->toBe(30.0)
            ->and((float) $line->remaining_to_secure)->toBe(10.0)
            ->and((float) $line->remaining_to_order)->toBe(10.0)
            ->and($line->contexts_count)->toBe(1)
            ->and($context->context_type)->toBe('production')
            ->and((float) $context->wave_open_order_quantity)->toBe(30.0)
            ->and((float) $context->remaining_to_order)->toBe(10.0)
            ->and($summary['remaining_to_order'])->toBe('10,000 kg')
            ->and($summary['contexts_count'])->toBe(1);
    });

    it('respects wave stock reserves in the operational overview', function () {
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile Ricin']);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'unit_weight' => 1,
        ]);

        $wave = ProductionWave::factory()->create([
            'name' => 'Vague Reserve',
            'status' => WaveStatus::Approved,
            'planned_start_date' => '2026-03-20',
        ]);

        $production = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'status' => ProductionStatus::Planned,
            'production_date' => '2026-03-20',
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 80.0,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $listing->supplies()->create([
            'order_ref' => 'PO-STOCK-OPER-RESERVE-001',
            'batch_number' => 'LOT-STOCK-OPER-RESERVE-001',
            'initial_quantity' => 100.0,
            'quantity_in' => 100.0,
            'quantity_out' => 0.0,
            'allocated_quantity' => 0.0,
            'unit_price' => 4.4,
            'expiry_date' => now()->addMonths(6),
            'delivery_date' => now(),
            'is_in_stock' => true,
        ]);

        ProductionWaveStockDecision::factory()->create([
            'production_wave_id' => $wave->id,
            'ingredient_id' => $ingredient->id,
            'reserved_quantity' => 30.0,
        ]);

        $line = waveProcurementService()->getOperationalPlanningList()->sole();
        $summary = waveProcurementService()->getOperationalPlanningSummary();
        $context = $line->contexts->sole();

        expect($line->ingredient_name)->toBe('Huile Ricin')
            ->and((float) $line->available_stock)->toBe(100.0)
            ->and((float) $line->reserved_stock_quantity)->toBe(30.0)
            ->and((float) $line->planned_stock_quantity)->toBe(70.0)
            ->and((float) $line->remaining_to_secure)->toBe(10.0)
            ->and((float) $line->remaining_to_order)->toBe(10.0)
            ->and($context->context_type)->toBe('wave')
            ->and((float) $context->stock_priority_quantity)->toBe(70.0)
            ->and((float) $context->remaining_to_secure)->toBe(10.0)
            ->and((float) $context->remaining_to_order)->toBe(10.0)
            ->and($summary['available_stock'])->toBe('100,000 kg')
            ->and($summary['remaining_to_order'])->toBe('10,000 kg')
            ->and($summary['ingredients_to_order'])->toBe(1);
    });
});
