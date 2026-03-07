<?php

use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Enums\WaveStatus;
use App\Models\Production\Formula;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use App\Services\Production\WaveDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function waveDeletionService(): WaveDeletionService
{
    return app(WaveDeletionService::class);
}

function createWaveDeletionProduction(ProductionWave $wave, string $batchNumber, ProductionStatus $status = ProductionStatus::Planned): Production
{
    $product = \App\Models\Production\Product::factory()->create();

    $formula = Formula::query()->create([
        'name' => 'Formula delete wave '.Str::uuid(),
        'slug' => Str::slug('formula-delete-wave-'.Str::uuid()),
        'code' => 'FRM-'.Str::upper(Str::random(8)),
        'is_active' => true,
        'date_of_creation' => now()->toDateString(),
    ]);

    return Production::withoutEvents(function () use ($wave, $product, $formula, $batchNumber, $status): Production {
        return Production::query()->create([
            'production_wave_id' => $wave->id,
            'product_id' => $product->id,
            'formula_id' => $formula->id,
            'batch_number' => $batchNumber,
            'slug' => Str::slug($batchNumber.'-'.Str::uuid()),
            'status' => $status,
            'sizing_mode' => SizingMode::OilWeight,
            'planned_quantity' => 10,
            'expected_units' => 100,
            'production_date' => now()->toDateString(),
            'ready_date' => now()->addDays(2)->toDateString(),
            'organic' => true,
        ]);
    });
}

it('blocks hard deletion when reserved allocations are present', function (): void {
    $wave = ProductionWave::factory()->create(['status' => WaveStatus::Approved]);
    $production = createWaveDeletionProduction($wave, 'T99101');

    $ingredient = Ingredient::factory()->create();
    $supply = Supply::factory()->inStock(100)->create();

    $item = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 8,
        'supply_id' => $supply->id,
    ]);

    ProductionItemAllocation::query()->create([
        'production_item_id' => $item->id,
        'supply_id' => $supply->id,
        'quantity' => 8,
        'status' => 'reserved',
        'reserved_at' => now(),
    ]);

    expect(fn () => waveDeletionService()->hardDeleteWaveWithProductions($wave))
        ->toThrow(\InvalidArgumentException::class, 'Allocations réservées actives');
});

it('blocks hard deletion when committed supplier order quantities exist', function (): void {
    $wave = ProductionWave::factory()->create(['status' => WaveStatus::Approved]);
    createWaveDeletionProduction($wave, 'T99102');

    $supplier = Supplier::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
    ]);

    $order = SupplierOrder::factory()->confirmed()->create([
        'supplier_id' => $supplier->id,
        'production_wave_id' => $wave->id,
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'committed_quantity_kg' => 25,
        'moved_to_stock_at' => null,
    ]);

    expect(fn () => waveDeletionService()->hardDeleteWaveWithProductions($wave))
        ->toThrow(\InvalidArgumentException::class, 'Engagements PO actifs');
});

it('blocks hard deletion for waves without productions when committed PO lines are linked', function (): void {
    $wave = ProductionWave::factory()->create(['status' => WaveStatus::Draft]);

    $supplier = Supplier::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
    ]);

    $order = SupplierOrder::factory()->confirmed()->create([
        'supplier_id' => $supplier->id,
        'production_wave_id' => $wave->id,
        'order_ref' => 'PO-WAVE-LOCK',
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'committed_quantity_kg' => 5,
        'moved_to_stock_at' => null,
    ]);

    expect(fn () => waveDeletionService()->hardDeleteWaveWithProductions($wave))
        ->toThrow(\InvalidArgumentException::class, 'PO-WAVE-LOCK');
});

it('hard deletes wave and linked productions when no blockers remain', function (): void {
    $wave = ProductionWave::factory()->create(['status' => WaveStatus::Cancelled]);
    $productionA = createWaveDeletionProduction($wave, 'T99103');
    $productionB = createWaveDeletionProduction($wave, 'T99104', ProductionStatus::Cancelled);

    waveDeletionService()->hardDeleteWaveWithProductions($wave);

    expect(ProductionWave::withTrashed()->whereKey($wave->id)->exists())->toBeFalse()
        ->and(Production::withTrashed()->whereKey($productionA->id)->exists())->toBeFalse()
        ->and(Production::withTrashed()->whereKey($productionB->id)->exists())->toBeFalse();
});
