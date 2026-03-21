<?php

use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use App\Models\Supply\SupplierOrder;
use App\Services\OptimisticLocking\AggregateVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(AggregateVersionService::class);
});

function createProductionForTest(array $attributes = []): Production
{
    $productType = ProductType::first() ?? ProductType::factory()->create();
    $formula = Formula::first() ?? Formula::factory()->create();
    $product = Product::factory()->create(['product_type_id' => $productType->id]);

    return Production::create(array_merge([
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'product_type_id' => $productType->id,
        'status' => 'planned',
        'planned_quantity' => 10.000,
        'batch_number' => 'T'.str_pad((string) rand(1, 99999), 5, '0', STR_PAD_LEFT),
        'slug' => Str::slug('batch-test-'.rand(1000, 9999)),
        'production_date' => now()->format('Y-m-d'),
        'lock_version' => 0,
    ], $attributes));
}

function createWaveForVersionTest(array $attributes = []): ProductionWave
{
    return ProductionWave::factory()->create(array_merge([
        'lock_version' => 0,
    ], $attributes));
}

function createSupplierOrderForVersionTest(array $attributes = []): SupplierOrder
{
    return SupplierOrder::factory()->create(array_merge([
        'lock_version' => 0,
    ], $attributes));
}

describe('AggregateVersionService', function () {
    it('bumps production version', function () {
        $production = createProductionForTest(['lock_version' => 0]);

        $this->service->bumpProductionVersion($production);

        $production->refresh();
        expect($production->lock_version)->toBe(1);
    });

    it('increments version multiple times', function () {
        $production = createProductionForTest(['lock_version' => 0]);

        $this->service->bumpProductionVersion($production);
        $this->service->bumpProductionVersion($production);
        $this->service->bumpProductionVersion($production);

        $production->refresh();
        expect($production->lock_version)->toBe(3);
    });

    it('bumps version for existing productions with non-zero version', function () {
        $production = createProductionForTest(['lock_version' => 5]);

        $this->service->bumpProductionVersion($production);

        $production->refresh();
        expect($production->lock_version)->toBe(6);
    });

    it('bumps the linked wave when bumping a production version', function () {
        $wave = createWaveForVersionTest(['lock_version' => 0]);
        $production = createProductionForTest([
            'production_wave_id' => $wave->id,
            'lock_version' => 0,
        ]);

        $wave->refresh();
        $initialWaveVersion = $wave->lock_version;

        $this->service->bumpProductionVersion($production);

        expect($production->fresh()->lock_version)->toBe(1)
            ->and($wave->fresh()->lock_version)->toBe($initialWaveVersion + 1);
    });

    it('bumps the linked wave when bumping a supplier order version', function () {
        $wave = createWaveForVersionTest(['lock_version' => 0]);
        $order = createSupplierOrderForVersionTest([
            'production_wave_id' => $wave->id,
            'lock_version' => 0,
        ]);

        $wave->refresh();
        $initialWaveVersion = $wave->lock_version;

        $this->service->bumpSupplierOrderVersion($order);

        expect($order->fresh()->lock_version)->toBe(1)
            ->and($wave->fresh()->lock_version)->toBe($initialWaveVersion + 1);
    });
});
