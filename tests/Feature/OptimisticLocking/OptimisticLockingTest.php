<?php

use App\Enums\ProductionStatus;
use App\Filament\Traits\UsesOptimisticLocking;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductionWaveStockDecision;
use App\Models\Production\ProductType;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use App\Services\OptimisticLocking\AggregateVersionService;
use App\Services\Production\ProductionAllocationService;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

class OptimisticLockingHarness
{
    use UsesOptimisticLocking;

    public array $filledState = [];

    public object $form;

    public function __construct(public Production $record)
    {
        $this->form = new class($this)
        {
            public function __construct(private readonly OptimisticLockingHarness $owner) {}

            public function fill(array $state): void
            {
                $this->owner->filledState = $state;
            }
        };

        $this->initializeOptimisticLocking();
    }

    public function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRecord(array $data): Production
    {
        /** @var Production $record */
        $record = $this->handleRecordUpdateWithOptimisticLock($this->record, $data);

        return $record;
    }

    public function reloadRecord(): void
    {
        $this->reloadRecordFromDatabase();
    }

    public function refreshLoadedVersionAfterSave(): void
    {
        $this->refreshLockVersionAfterSave();
    }
}

function createTestProduction(array $attributes = []): Production
{
    $productType = ProductType::first() ?? ProductType::factory()->create();
    $formula = Formula::first() ?? Formula::factory()->create();
    $product = Product::factory()->create(['product_type_id' => $productType->id]);

    return Production::create(array_merge([
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'product_type_id' => $productType->id,
        'status' => ProductionStatus::Planned->value,
        'planned_quantity' => 10.000,
        'batch_number' => 'T'.str_pad((string) rand(1, 99999), 5, '0', STR_PAD_LEFT),
        'slug' => Str::slug('batch-test-'.rand(1000, 9999)),
        'production_date' => now()->format('Y-m-d'),
        'lock_version' => 0,
    ], $attributes));
}

function createTestWave(array $attributes = []): ProductionWave
{
    return ProductionWave::factory()->create(array_merge([
        'lock_version' => 0,
    ], $attributes));
}

function createTestSupplierOrder(array $attributes = []): SupplierOrder
{
    return SupplierOrder::factory()->create(array_merge([
        'lock_version' => 0,
    ], $attributes));
}

describe('Optimistic Locking - Version Bumping', function () {
    it('increments lock_version when production is updated', function () {
        $production = createTestProduction(['lock_version' => 0]);

        $production->notes = 'Updated notes';
        $production->save();

        expect($production->fresh()->lock_version)->toBe(1);
    });

    it('increments lock_version when production status changes', function () {
        $production = createTestProduction([
            'lock_version' => 0,
            'status' => ProductionStatus::Planned->value,
        ]);

        $production->notes = 'test';
        $production->save();

        $production->status = ProductionStatus::Confirmed->value;
        $production->save();

        expect($production->fresh()->lock_version)->toBe(2);
    });

    it('increments lock_version when production item is created', function () {
        $production = createTestProduction(['lock_version' => 0]);

        ProductionItem::factory()->for($production)->create();

        expect($production->fresh()->lock_version)->toBe(1);
    });

    it('increments lock_version when production item is updated', function () {
        $production = createTestProduction(['lock_version' => 0]);
        $item = ProductionItem::factory()->for($production)->create();

        $production->refresh();
        $initialVersion = $production->lock_version;

        $item->required_quantity = 10.0;
        $item->save();

        expect($production->fresh()->lock_version)->toBe($initialVersion + 1);
    });

    it('increments lock_version when production task is updated', function () {
        $production = createTestProduction(['lock_version' => 0]);
        $task = ProductionTask::create([
            'production_id' => $production->id,
            'name' => 'Test Task',
            'date' => now()->format('Y-m-d'),
        ]);

        $production->refresh();
        $initialVersion = $production->lock_version;

        $task->is_finished = true;
        $task->save();

        expect($production->fresh()->lock_version)->toBe($initialVersion + 1);
    });

    it('increments lock_version when a production wave is updated', function () {
        $wave = createTestWave(['lock_version' => 0]);

        $wave->update([
            'notes' => 'Updated wave notes',
        ]);

        expect($wave->fresh()->lock_version)->toBe(1);
    });

    it('increments lock_version when a production wave stock decision is created', function () {
        $wave = createTestWave(['lock_version' => 0]);

        ProductionWaveStockDecision::factory()->for($wave, 'wave')->create();

        expect($wave->fresh()->lock_version)->toBe(1);
    });

    it('increments lock_version when a supplier order is updated', function () {
        $order = createTestSupplierOrder(['lock_version' => 0]);

        $order->update([
            'description' => 'Updated description',
        ]);

        expect($order->fresh()->lock_version)->toBe(1);
    });

    it('increments lock_version when a supplier order item is created', function () {
        $order = createTestSupplierOrder(['lock_version' => 0]);

        SupplierOrderItem::factory()->for($order, 'supplierOrder')->create();

        expect($order->fresh()->lock_version)->toBe(1);
    });

    it('increments lock_version when a supplier order item is updated', function () {
        $order = createTestSupplierOrder(['lock_version' => 0]);
        $item = SupplierOrderItem::factory()->for($order, 'supplierOrder')->create();

        $order->refresh();
        $initialVersion = $order->lock_version;

        $item->update([
            'unit_price' => 42.50,
        ]);

        expect($order->fresh()->lock_version)->toBe($initialVersion + 1);
    });

    it('bumps both the old and new wave versions when moving a production between waves', function () {
        $oldWave = createTestWave(['lock_version' => 0]);
        $newWave = createTestWave(['lock_version' => 0]);
        $production = createTestProduction([
            'production_wave_id' => $oldWave->id,
            'lock_version' => 0,
        ]);

        $oldWave->refresh();
        $newWave->refresh();

        $initialOldWaveVersion = $oldWave->lock_version;
        $initialNewWaveVersion = $newWave->lock_version;

        $production->update([
            'production_wave_id' => $newWave->id,
        ]);

        expect($oldWave->fresh()->lock_version)->toBeGreaterThan($initialOldWaveVersion)
            ->and($newWave->fresh()->lock_version)->toBeGreaterThan($initialNewWaveVersion);
    });

    it('bumps both the old and new wave versions when moving a supplier order between waves', function () {
        $oldWave = createTestWave(['lock_version' => 0]);
        $newWave = createTestWave(['lock_version' => 0]);
        $order = createTestSupplierOrder([
            'production_wave_id' => $oldWave->id,
            'lock_version' => 0,
        ]);

        $oldWave->refresh();
        $newWave->refresh();

        $initialOldWaveVersion = $oldWave->lock_version;
        $initialNewWaveVersion = $newWave->lock_version;

        $order->update([
            'production_wave_id' => $newWave->id,
        ]);

        expect($oldWave->fresh()->lock_version)->toBeGreaterThan($initialOldWaveVersion)
            ->and($newWave->fresh()->lock_version)->toBeGreaterThan($initialNewWaveVersion);
    });
});

describe('AggregateVersionService', function () {
    it('bumps production version correctly', function () {
        $production = createTestProduction(['lock_version' => 0]);
        $service = app(AggregateVersionService::class);

        $service->bumpProductionVersion($production);

        expect($production->fresh()->lock_version)->toBe(1);
    });

    it('bumps production version for service updates', function () {
        $production = createTestProduction(['lock_version' => 0]);
        $service = app(AggregateVersionService::class);

        $service->bumpProductionVersion($production);
        $service->bumpProductionVersion($production);
        $service->bumpProductionVersion($production);

        expect($production->fresh()->lock_version)->toBe(3);
    });
});

describe('Optimistic Locking - Version Check', function () {
    it('detects version mismatch when record is modified externally', function () {
        $production = createTestProduction(['lock_version' => 0]);

        $loadedVersion = $production->lock_version;

        $production->notes = 'External modification';
        $production->save();

        $currentVersion = $production->fresh()->lock_version;

        expect($currentVersion)->not->toBe($loadedVersion);
        expect($currentVersion)->toBe(1);
    });

    it('blocks a stale update in the atomic record update path', function () {
        $production = createTestProduction(['lock_version' => 0]);
        $harness = new OptimisticLockingHarness($production);

        $production->update([
            'notes' => 'External modification',
        ]);

        expect(function () use ($harness): void {
            $harness->updateRecord([
                'notes' => 'Stale form save',
                'lock_version' => $harness->getLoadedLockVersion() + 1,
            ]);
        })->toThrow(Halt::class);

        expect($production->fresh()->notes)->toBe('External modification');
    });

    it('reloads the latest record data and lock version from the database', function () {
        $production = createTestProduction(['lock_version' => 0]);
        $harness = new OptimisticLockingHarness($production);

        $production->update([
            'notes' => 'Updated by service',
        ]);

        $harness->reloadRecord();

        expect($harness->getLoadedLockVersion())->toBe($production->fresh()->lock_version)
            ->and($harness->filledState['notes'])->toBe('Updated by service')
            ->and($harness->hasExternalUpdateDetected())->toBeFalse();
    });

    it('refreshes the loaded version after a successful save', function () {
        $production = createTestProduction(['lock_version' => 0]);
        $harness = new OptimisticLockingHarness($production);

        $harness->updateRecord([
            'notes' => 'First save',
            'lock_version' => $harness->getLoadedLockVersion() + 1,
        ]);

        $harness->refreshLoadedVersionAfterSave();

        expect($harness->getLoadedLockVersion())->toBe($production->fresh()->lock_version);
    });

    it('bumps production version when releasing all allocations through the service', function () {
        $production = createTestProduction(['lock_version' => 0]);
        $item = ProductionItem::factory()->for($production)->create();
        $supply = Supply::factory()->create();

        ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 2.5,
            'status' => 'reserved',
        ]);

        $production->refresh();
        $initialVersion = $production->lock_version;

        app(ProductionAllocationService::class)->releaseAll($item);

        expect($production->fresh()->lock_version)->toBe($initialVersion + 1);
    });
});
