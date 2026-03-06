<?php

use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Enums\WaveStatus;
use App\Livewire\WaveProcurementOverviewPage;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders global procurement lines for active waves', function (): void {
    $supplier = Supplier::factory()->create();

    $ingredientShortage = Ingredient::factory()->create(['name' => 'Huile Ricin']);
    $ingredientCovered = Ingredient::factory()->create(['name' => 'Huile Olive']);

    $listingShortage = SupplierListing::factory()->create([
        'ingredient_id' => $ingredientShortage->id,
        'supplier_id' => $supplier->id,
    ]);

    $listingCovered = SupplierListing::factory()->create([
        'ingredient_id' => $ingredientCovered->id,
        'supplier_id' => $supplier->id,
    ]);

    $wave = ProductionWave::factory()->create([
        'status' => WaveStatus::Approved,
    ]);

    $product = Product::factory()->create();
    $formula = Formula::query()->create([
        'name' => 'Formula wave procurement overview',
        'slug' => Str::slug('formula-wave-overview-'.Str::uuid()),
        'code' => 'FRM-'.Str::upper(Str::random(8)),
        'is_active' => true,
    ]);

    $production = Production::withoutEvents(function () use ($wave, $product, $formula): Production {
        return Production::query()->create([
            'production_wave_id' => $wave->id,
            'product_id' => $product->id,
            'formula_id' => $formula->id,
            'batch_number' => 'T98101',
            'slug' => 'batch-wave-overview',
            'status' => ProductionStatus::Planned,
            'sizing_mode' => SizingMode::OilWeight,
            'planned_quantity' => 10,
            'expected_units' => 100,
            'production_date' => '2026-03-12',
            'ready_date' => '2026-03-14',
            'organic' => true,
        ]);
    });

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredientShortage->id,
        'supplier_listing_id' => $listingShortage->id,
        'required_quantity' => 20,
        'procurement_status' => ProcurementStatus::NotOrdered,
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredientCovered->id,
        'supplier_listing_id' => $listingCovered->id,
        'required_quantity' => 10,
        'procurement_status' => ProcurementStatus::NotOrdered,
    ]);

    $listingCovered->supplies()->create([
        'order_ref' => 'PO-COVER-001',
        'batch_number' => 'LOT-COVER-001',
        'initial_quantity' => 10,
        'quantity_in' => 10,
        'quantity_out' => 0,
        'allocated_quantity' => 0,
        'unit_price' => 6.1,
        'expiry_date' => now()->addYear(),
        'delivery_date' => now(),
        'is_in_stock' => true,
    ]);

    Livewire::test(WaveProcurementOverviewPage::class)
        ->assertSee('Pilotage achats - vagues actives')
        ->assertSee('Huile Ricin')
        ->assertSee('Huile Olive');
});
