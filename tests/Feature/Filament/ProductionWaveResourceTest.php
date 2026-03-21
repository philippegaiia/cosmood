<?php

use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Enums\WaveStatus;
use App\Filament\Resources\Production\ProductionResource\Pages\ListProductions;
use App\Filament\Resources\Production\ProductionWaves\Pages\CreateProductionWave;
use App\Filament\Resources\Production\ProductionWaves\Pages\EditProductionWave;
use App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves;
use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use App\Filament\Resources\Production\ProductionWaves\RelationManagers\ProductionsRelationManager;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionTaskType;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductionWaveStockDecision;
use App\Models\Production\ProductType;
use App\Models\Production\TaskTemplate;
use App\Models\ResourceLock;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->assignRole(Role::findOrCreate('manager'));
    $this->actingAs($this->user);
});

function createWavePageProduction(ProductionWave $wave, ProductionLine $line, ProductionStatus $status, string $batchNumber, string $productionDate, ?int $productTypeId = null): Production
{
    $product = Product::factory()->create([
        'product_type_id' => $productTypeId,
    ]);

    $formula = Formula::query()->create([
        'name' => 'Formula '.Str::uuid(),
        'slug' => Str::slug('formula-'.Str::uuid()),
        'code' => 'FRM-'.Str::upper(Str::random(8)),
        'is_active' => true,
    ]);

    return Production::query()->create([
        'production_wave_id' => $wave->id,
        'production_line_id' => $line->id,
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'batch_number' => $batchNumber,
        'slug' => Str::slug($batchNumber.'-'.Str::uuid()),
        'status' => $status,
        'product_type_id' => $productTypeId,
        'sizing_mode' => SizingMode::OilWeight,
        'planned_quantity' => 10,
        'expected_units' => 100,
        'production_date' => $productionDate,
        'ready_date' => now()->addDays(2)->toDateString(),
        'organic' => true,
    ]);
}

describe('ProductionWave Model', function () {
    it('can be created with factory', function () {
        $wave = ProductionWave::factory()->create();

        expect($wave)
            ->toBeInstanceOf(ProductionWave::class)
            ->and($wave->name)->not->toBeEmpty()
            ->and($wave->slug)->not->toBeEmpty();
    });

    it('has draft status by default', function () {
        $wave = ProductionWave::factory()->create();

        expect($wave->status)->toBe(WaveStatus::Draft);
    });

    it('can be approved', function () {
        $user = User::factory()->create();
        $wave = ProductionWave::factory()->approved()->create([
            'approved_by' => $user->id,
        ]);

        expect($wave->status)->toBe(WaveStatus::Approved)
            ->and($wave->approved_by)->toBe($user->id)
            ->and($wave->approved_at)->not->toBeNull()
            ->and($wave->planned_start_date)->not->toBeNull()
            ->and($wave->planned_end_date)->not->toBeNull();
    });

    it('can be in progress', function () {
        $wave = ProductionWave::factory()->inProgress()->create();

        expect($wave->status)->toBe(WaveStatus::InProgress)
            ->and($wave->started_at)->not->toBeNull();
    });

    it('can be completed', function () {
        $wave = ProductionWave::factory()->completed()->create();

        expect($wave->status)->toBe(WaveStatus::Completed)
            ->and($wave->completed_at)->not->toBeNull();
    });
});

describe('ProductionWave - Relationships', function () {
    it('can have productions', function () {
        $wave = ProductionWave::factory()->create();
        Production::factory()->count(3)->forWave($wave)->create();

        expect($wave->productions)->toHaveCount(3);
    });

    it('productions can be orphan', function () {
        $orphanProduction = Production::factory()->orphan()->create();

        expect($orphanProduction->isOrphan())->toBeTrue()
            ->and($orphanProduction->production_wave_id)->toBeNull();
    });

    it('registers productions relation manager for wave edit page', function () {
        $relations = ProductionWaveResource::getRelations();

        expect($relations)->toContain(ProductionsRelationManager::class);
    });

    it('shows procurement signal and manual order marker in related productions tab', function () {
        $wave = ProductionWave::factory()->approved()->create();
        $production = Production::factory()->forWave($wave)->confirmed()->create();

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'is_order_marked' => true,
            'procurement_status' => ProcurementStatus::Ordered,
        ]);

        Livewire::test(ProductionsRelationManager::class, [
            'ownerRecord' => $wave,
            'pageClass' => EditProductionWave::class,
        ])
            ->assertSee('Commandé')
            ->assertSee('Oui (1)');
    });

    it('confirms a planned production from wave related productions table action', function () {
        $wave = ProductionWave::factory()->approved()->create();
        $production = Production::factory()->forWave($wave)->planned()->create();

        Livewire::test(ProductionsRelationManager::class, [
            'ownerRecord' => $wave,
            'pageClass' => EditProductionWave::class,
        ])
            ->callAction(TestAction::make('confirmProduction')->table($production))
            ->assertHasNoErrors();

        expect($production->fresh()->status)->toBe(ProductionStatus::Confirmed);
    });

    it('shows bulk confirm action on wave related productions table', function () {
        $wave = ProductionWave::factory()->approved()->create();
        Production::factory()->count(2)->forWave($wave)->planned()->create();

        Livewire::test(ProductionsRelationManager::class, [
            'ownerRecord' => $wave,
            'pageClass' => EditProductionWave::class,
        ])
            ->assertSee('Confirmer sélection');
    });

    it('shows productions relation manager on edit wave page', function () {
        $wave = ProductionWave::factory()->create();
        Production::factory()->forWave($wave)->create();

        Livewire::test(EditProductionWave::class, [
            'record' => $wave->id,
        ])->assertSeeLivewire(ProductionsRelationManager::class);
    });

    it('shows the approvisionnement tab on edit wave page', function () {
        $wave = ProductionWave::factory()->create([
            'planned_start_date' => '2026-03-20',
        ]);

        Livewire::test(EditProductionWave::class, [
            'record' => $wave->id,
        ])
            ->assertSee('Approvisionnement')
            ->assertSee('overflow-x-auto')
            ->assertSee('table-fixed')
            ->assertSee('Besoin total')
            ->assertSee('Reste à commander')
            ->assertSee('Commandes ouvertes non engagées');
    });

    it('lists only productions attached to the current wave in relation manager', function () {
        $wave = ProductionWave::factory()->create();
        $otherWave = ProductionWave::factory()->create();

        $waveProductions = Production::factory()->count(2)->forWave($wave)->create();
        $otherWaveProduction = Production::factory()->forWave($otherWave)->create();

        Livewire::test(ProductionsRelationManager::class, [
            'ownerRecord' => $wave,
            'pageClass' => EditProductionWave::class,
        ])
            ->assertCanSeeTableRecords($waveProductions)
            ->assertCanNotSeeTableRecords([$otherWaveProduction]);
    });
});

describe('ProductionWave - Status Helpers', function () {
    it('can check status', function () {
        $draftWave = ProductionWave::factory()->draft()->create();
        $approvedWave = ProductionWave::factory()->approved()->create();
        $inProgressWave = ProductionWave::factory()->inProgress()->create();
        $completedWave = ProductionWave::factory()->completed()->create();

        expect($draftWave->isDraft())->toBeTrue()
            ->and($draftWave->isApproved())->toBeFalse()
            ->and($approvedWave->isApproved())->toBeTrue()
            ->and($inProgressWave->isInProgress())->toBeTrue()
            ->and($completedWave->isCompleted())->toBeTrue();
    });
});

describe('ProductionWave - Status Transitions', function () {
    it('can transition from draft to approved', function () {
        $wave = ProductionWave::factory()->draft()->create();
        $user = User::factory()->create();

        $wave->update([
            'status' => WaveStatus::Approved,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'planned_start_date' => now()->addDays(7),
            'planned_end_date' => now()->addDays(14),
        ]);

        expect($wave->fresh()->status)->toBe(WaveStatus::Approved);
    });

    it('can be cancelled', function () {
        $wave = ProductionWave::factory()->approved()->create();

        $wave->update(['status' => WaveStatus::Cancelled]);

        expect($wave->fresh()->status)->toBe(WaveStatus::Cancelled);
    });
});

describe('ProductionWave Presence Locking', function () {
    it('blocks a second editor while another manager owns the edit page', function () {
        $firstUser = User::factory()->create();
        $firstUser->assignRole(Role::findOrCreate('manager'));

        $secondUser = User::factory()->create();
        $secondUser->assignRole(Role::findOrCreate('manager'));

        $wave = ProductionWave::factory()->create();

        $this->actingAs($firstUser);

        Livewire::test(EditProductionWave::class, ['record' => $wave->id])
            ->assertSet('hasForeignPresenceLock', false);

        $this->actingAs($secondUser);

        Livewire::test(EditProductionWave::class, ['record' => $wave->id])
            ->assertSet('hasForeignPresenceLock', true)
            ->assertSee(__('presence-locking.blocked_title'))
            ->assertDontSee('Approvisionnement');
    });

    it('lets a manager force unlock and take over the wave edit page', function () {
        $firstUser = User::factory()->create();
        $firstUser->assignRole(Role::findOrCreate('manager'));

        $secondUser = User::factory()->create();
        $secondUser->assignRole(Role::findOrCreate('manager'));

        $wave = ProductionWave::factory()->create();

        $this->actingAs($firstUser);

        Livewire::test(EditProductionWave::class, ['record' => $wave->id])
            ->assertSet('hasForeignPresenceLock', false);

        $this->actingAs($secondUser);

        Livewire::test(EditProductionWave::class, ['record' => $wave->id])
            ->assertSet('hasForeignPresenceLock', true)
            ->call('forceReleasePresenceLock')
            ->assertSet('hasForeignPresenceLock', false)
            ->assertSee('Approvisionnement');

        expect(ResourceLock::query()->sole()->user_id)->toBe($secondUser->id);
    });
});

describe('ProductionWave deletion contract', function () {
    it('blocks direct wave deletion outside the managed service', function () {
        $wave = ProductionWave::factory()->create();

        expect(fn () => $wave->delete())
            ->toThrow(InvalidArgumentException::class, 'Utilisez la suppression définitive de la vague');
    });
});

describe('ProductionWaveResource - table actions', function () {
    it('creates wave as draft without requiring planned end date on create page', function () {
        Livewire::test(CreateProductionWave::class)
            ->fillForm([
                'name' => 'Vague création légère',
                'slug' => 'vague-creation-legere',
            ])
            ->call('create')
            ->assertHasNoErrors();

        $wave = ProductionWave::query()->where('slug', 'vague-creation-legere')->first();

        expect($wave)->not->toBeNull()
            ->and($wave->status)->toBe(WaveStatus::Draft)
            ->and($wave->planned_end_date)->toBeNull();
    });

    it('approves draft wave with planned dates set as strings', function () {
        $wave = ProductionWave::factory()->draft()->create([
            'planned_start_date' => now()->addDays(7)->toDateString(),
            'planned_end_date' => now()->addDays(10)->toDateString(),
        ]);

        Livewire::test(ListProductionWaves::class)
            ->callAction(TestAction::make('approve')->table($wave))
            ->assertHasNoErrors();

        expect($wave->fresh()->status)->toBe(WaveStatus::Approved);
    });

    it('approves a draft wave from list action', function () {
        $wave = ProductionWave::factory()->draft()->create();

        Livewire::test(ListProductionWaves::class)
            ->callAction(TestAction::make('approve')->table($wave))
            ->assertHasNoErrors();

        expect($wave->fresh()->status)->toBe(WaveStatus::Approved);
    });

    it('starts an approved wave from list action', function () {
        $wave = ProductionWave::factory()->approved()->create();

        Livewire::test(ListProductionWaves::class)
            ->callAction(TestAction::make('start')->table($wave))
            ->assertHasNoErrors();

        expect($wave->fresh()->status)->toBe(WaveStatus::InProgress);
    });

    it('shows procurement coverage legend action on list page', function () {
        Livewire::test(ListProductionWaves::class)
            ->assertSee('Légende signaux');
    });

    it('marks only not ordered items for selected ingredients at wave level', function () {
        $wave = ProductionWave::factory()->approved()->create();
        $production = Production::factory()->forWave($wave)->planned()->create();
        $supplier = Supplier::factory()->create();
        $ingredientMarked = Ingredient::factory()->create(['name' => 'Huile de coco']);
        $ingredientUntouched = Ingredient::factory()->create(['name' => 'Soude']);

        $markedListing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredientMarked->id,
            'supplier_id' => $supplier->id,
        ]);

        $untouchedListing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredientUntouched->id,
            'supplier_id' => $supplier->id,
        ]);

        $itemToMark = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredientMarked->id,
            'supplier_listing_id' => $markedListing->id,
            'procurement_status' => ProcurementStatus::NotOrdered,
            'is_order_marked' => false,
            'required_quantity' => 8,
        ]);

        $itemAlreadyOrdered = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredientMarked->id,
            'supplier_listing_id' => $markedListing->id,
            'procurement_status' => ProcurementStatus::Ordered,
            'is_order_marked' => false,
            'required_quantity' => 4,
        ]);

        $itemUntouched = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredientUntouched->id,
            'supplier_listing_id' => $untouchedListing->id,
            'procurement_status' => ProcurementStatus::NotOrdered,
            'is_order_marked' => false,
            'required_quantity' => 3,
        ]);

        Livewire::test(ListProductionWaves::class)
            ->callAction(TestAction::make('markWaveIngredientOrdered')->table($wave), [
                'ingredient_ids' => [(string) $ingredientMarked->id],
            ])
            ->assertHasNoErrors();

        expect($itemToMark->fresh()->is_order_marked)->toBeTrue()
            ->and($itemToMark->fresh()->procurement_status)->toBe(ProcurementStatus::Ordered)
            ->and($itemAlreadyOrdered->fresh()->is_order_marked)->toBeFalse()
            ->and($itemUntouched->fresh()->is_order_marked)->toBeFalse();
    });

    it('marks ordered items from committed wave orders in the edit wave header action', function () {
        $wave = ProductionWave::factory()->approved()->create([
            'planned_start_date' => '2026-03-20',
        ]);
        $production = Production::factory()->forWave($wave)->planned()->create();
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile de coco']);

        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'procurement_status' => ProcurementStatus::NotOrdered,
            'is_order_marked' => false,
            'required_quantity' => 8,
        ]);

        $order = SupplierOrder::factory()
            ->passed()
            ->forWave($wave)
            ->create([
                'supplier_id' => $supplier->id,
            ]);

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $order->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 1,
            'unit_weight' => 8,
            'committed_quantity_kg' => 8,
        ]);

        Livewire::test(EditProductionWave::class, [
            'record' => $wave->id,
        ])
            ->callAction('markWaveItemsOrdered')
            ->assertHasNoErrors();

        expect($item->fresh()->is_order_marked)->toBeTrue()
            ->and($item->fresh()->procurement_status)->toBe(ProcurementStatus::Ordered);
    });

    it('stores a stock reserve decision for a wave ingredient from the edit wave header action', function () {
        $wave = ProductionWave::factory()->approved()->create([
            'planned_start_date' => '2026-03-20',
        ]);
        $production = Production::factory()->forWave($wave)->planned()->create();
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile tournesol']);

        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        Supply::factory()->inStock(100)->create([
            'supplier_listing_id' => $listing->id,
            'is_in_stock' => true,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'procurement_status' => ProcurementStatus::NotOrdered,
            'required_quantity' => 80,
        ]);

        Livewire::test(EditProductionWave::class, [
            'record' => $wave->id,
        ])
            ->callAction('decideWaveStockReserve', [
                'ingredient_id' => (string) $ingredient->id,
                'reserved_quantity' => 30,
            ])
            ->assertHasNoErrors();

        $decision = ProductionWaveStockDecision::query()
            ->where('production_wave_id', $wave->id)
            ->where('ingredient_id', $ingredient->id)
            ->first();

        expect($decision)->not->toBeNull()
            ->and((float) $decision->reserved_quantity)->toBe(30.0);
    });

    it('allocates the planned stock for a wave ingredient from the edit wave header action', function () {
        $wave = ProductionWave::factory()->approved()->create([
            'planned_start_date' => '2026-03-20',
        ]);
        $production = Production::factory()->forWave($wave)->planned()->create();
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile olive']);

        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        $supply = Supply::factory()->inStock(100)->create([
            'supplier_listing_id' => $listing->id,
            'is_in_stock' => true,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'procurement_status' => ProcurementStatus::NotOrdered,
            'required_quantity' => 80,
        ]);

        Livewire::test(EditProductionWave::class, [
            'record' => $wave->id,
        ])
            ->callAction('allocateWaveIngredientStock', [
                'ingredient_id' => (string) $ingredient->id,
            ])
            ->assertHasNoErrors();

        expect((float) $item->fresh()->getTotalAllocatedQuantity())->toBe(80.0)
            ->and((float) $supply->fresh()->getAllocatedQuantity())->toBe(80.0);
    });

    it('does not create partial automatic allocation for a wave ingredient', function () {
        $wave = ProductionWave::factory()->approved()->create([
            'planned_start_date' => '2026-03-20',
        ]);
        $production = Production::factory()->forWave($wave)->planned()->create();
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile coco']);

        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        $supply = Supply::factory()->inStock(50)->create([
            'supplier_listing_id' => $listing->id,
            'is_in_stock' => true,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'procurement_status' => ProcurementStatus::NotOrdered,
            'required_quantity' => 80,
        ]);

        Livewire::test(EditProductionWave::class, [
            'record' => $wave->id,
        ])
            ->callAction('allocateWaveIngredientStock', [
                'ingredient_id' => (string) $ingredient->id,
                'allocation_quantity' => 50,
            ])
            ->assertHasNoErrors();

        expect((float) $item->fresh()->getTotalAllocatedQuantity())->toBe(0.0)
            ->and((float) $supply->fresh()->getAllocatedQuantity())->toBe(0.0);
    });

    it('opens procurement plan action for a wave', function () {
        $wave = ProductionWave::factory()->approved()->create();
        $production = Production::factory()->create(['production_wave_id' => $wave->id]);
        $ingredient = Ingredient::factory()->create();
        $supplier = Supplier::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        Livewire::test(ListProductionWaves::class)
            ->callAction(TestAction::make('procurementPlan')->table($wave))
            ->assertHasNoErrors();
    });

    it('opens procurement plan action even without requirements', function () {
        $wave = ProductionWave::factory()->approved()->create();

        Livewire::test(ListProductionWaves::class)
            ->callAction(TestAction::make('procurementPlan')->table($wave))
            ->assertHasNoErrors();
    });

    it('shows a securiser coverage badge when advisory shortage exists', function () {
        $wave = ProductionWave::factory()->approved()->create();
        $production = Production::factory()->create(['production_wave_id' => $wave->id]);
        $ingredient = Ingredient::factory()->create();
        $supplier = Supplier::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 12,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        Livewire::test(ListProductionWaves::class)
            ->assertSee('Couverture appro');

        expect($wave->fresh()->getCoverageSignalLabel())->toBe('À sécuriser');
    });

    it('shows prete coverage badge when commitment fully covers needs', function () {
        $wave = ProductionWave::factory()->approved()->create();
        $production = Production::factory()->create(['production_wave_id' => $wave->id]);
        $ingredient = Ingredient::factory()->create();
        $supplier = Supplier::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 10,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $order = SupplierOrder::factory()
            ->passed()
            ->forWave($wave)
            ->create([
                'supplier_id' => $supplier->id,
            ]);

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $order->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 1,
            'unit_weight' => 10,
            'committed_quantity_kg' => 10,
        ]);

        Livewire::test(ListProductionWaves::class)
            ->assertSee('Couverture appro');

        expect($wave->fresh()->getCoverageSignalLabel())->toBe('Prête');
    });

    it('shows partielle coverage badge when stock can cover but is not yet secured', function () {
        $wave = ProductionWave::factory()->approved()->create();
        $production = Production::factory()->create(['production_wave_id' => $wave->id]);
        $ingredient = Ingredient::factory()->create();
        $supplier = Supplier::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 10,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        Supply::factory()->inStock(20)->create([
            'supplier_listing_id' => $listing->id,
            'is_in_stock' => true,
        ]);

        Livewire::test(ListProductionWaves::class)
            ->assertSee('Couverture appro');

        expect($wave->fresh()->getCoverageSignalLabel())->toBe('Partielle');
    });

    it('keeps procurement ready when firm linked orders cover needs even if stock is available', function () {
        $wave = ProductionWave::factory()->approved()->create();
        $production = Production::factory()->create(['production_wave_id' => $wave->id]);
        $ingredient = Ingredient::factory()->create();
        $supplier = Supplier::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 10,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        Supply::factory()->inStock(20)->create([
            'supplier_listing_id' => $listing->id,
            'is_in_stock' => true,
        ]);

        $order = SupplierOrder::factory()
            ->passed()
            ->forWave($wave)
            ->create([
                'supplier_id' => $supplier->id,
            ]);

        SupplierOrderItem::factory()->create([
            'supplier_order_id' => $order->id,
            'supplier_listing_id' => $listing->id,
            'quantity' => 1,
            'unit_weight' => 10,
            'committed_quantity_kg' => 10,
        ]);

        Livewire::test(ListProductionWaves::class)
            ->assertSee('Couverture appro');

        expect($wave->fresh()->getCoverageSignalLabel())->toBe('Prête');
    });

    it('shows fabrication ready while procurement remains to secure when only packaging is missing', function () {
        $wave = ProductionWave::factory()->approved()->create();
        $production = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'status' => ProductionStatus::Confirmed,
        ]);
        $supplier = Supplier::factory()->create();
        $fabricationIngredient = Ingredient::factory()->create();
        $packagingIngredient = Ingredient::factory()->create(['is_packaging' => true]);
        $fabricationListing = SupplierListing::factory()->create([
            'ingredient_id' => $fabricationIngredient->id,
            'supplier_id' => $supplier->id,
        ]);
        $packagingListing = SupplierListing::factory()->create([
            'ingredient_id' => $packagingIngredient->id,
            'supplier_id' => $supplier->id,
        ]);
        $supply = Supply::factory()->inStock(10)->create([
            'supplier_listing_id' => $fabricationListing->id,
            'is_in_stock' => true,
        ]);

        $allocatedItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $fabricationIngredient->id,
            'supplier_listing_id' => $fabricationListing->id,
            'required_quantity' => 10,
            'phase' => Phases::Additives->value,
            'procurement_status' => ProcurementStatus::Received,
            'supply_id' => $supply->id,
        ]);

        ProductionItemAllocation::query()->create([
            'production_item_id' => $allocatedItem->id,
            'supply_id' => $supply->id,
            'quantity' => 10,
            'status' => 'reserved',
            'reserved_at' => now(),
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $packagingIngredient->id,
            'supplier_listing_id' => $packagingListing->id,
            'required_quantity' => 96,
            'phase' => Phases::Packaging->value,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        Livewire::test(ListProductionWaves::class)
            ->assertSee('Couverture appro')
            ->assertSee('Fabrication sécurisée');

        expect($wave->fresh()->getCoverageSignalLabel())->toBe('À sécuriser')
            ->and($wave->fresh()->getFabricationSignalLabel())->toBe('Prête');
    });

    it('uses different planning and execution labels when stock exists but fabrication lots are not yet allocated', function () {
        $wave = ProductionWave::factory()->approved()->create();
        $production = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'status' => ProductionStatus::Confirmed,
        ]);
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Huile de tournesol']);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'required_quantity' => 10,
            'phase' => Phases::Additives->value,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        Supply::factory()->inStock(10)->create([
            'supplier_listing_id' => $listing->id,
            'is_in_stock' => true,
        ]);

        Livewire::test(ListProductionWaves::class)
            ->assertSee('Fabrication sécurisée');

        Livewire::test(ListProductions::class)
            ->assertSee('Prêt à démarrer');

        expect($wave->fresh()->getFabricationSignalLabel())->toBe('Partielle')
            ->and($production->fresh()->getFabricationReadinessLabel())->toBe('À sécuriser');
    });

    it('renders the list page without exploding the query count for coverage badges', function () {
        $supplier = Supplier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Argile blanche']);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        Supply::factory()
            ->count(50)
            ->inStock(2.0)
            ->create([
                'supplier_listing_id' => $listing->id,
                'is_in_stock' => true,
            ]);

        foreach (range(1, 10) as $index) {
            $wave = ProductionWave::factory()->approved()->create([
                'planned_start_date' => now()->addDays($index)->toDateString(),
            ]);

            $production = Production::factory()->create([
                'production_wave_id' => $wave->id,
                'status' => ProductionStatus::Planned,
                'production_date' => now()->addDays($index)->toDateString(),
            ]);

            ProductionItem::factory()->create([
                'production_id' => $production->id,
                'ingredient_id' => $ingredient->id,
                'supplier_listing_id' => $listing->id,
                'required_quantity' => 5.0,
                'procurement_status' => ProcurementStatus::NotOrdered,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(ListProductionWaves::class)
            ->assertSee('Couverture appro')
            ->assertSee('Fabrication sécurisée')
            ->assertSee('Partielle');

        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        expect($queryCount)->toBeLessThan(120);
    });

    it('replans wave productions from selected start date', function () {
        $line = ProductionLine::factory()->soapLine()->create([
            'daily_batch_capacity' => 2,
        ]);

        $wave = ProductionWave::factory()->approved()->create();

        $product = Product::factory()->create();
        $formula = Formula::query()->create([
            'name' => 'Formula wave replan',
            'slug' => Str::slug('formula-wave-replan-'.Str::uuid()),
            'code' => 'FRM-'.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $first = Production::query()->create([
            'production_wave_id' => $wave->id,
            'production_line_id' => $line->id,
            'product_id' => $product->id,
            'formula_id' => $formula->id,
            'batch_number' => 'T94001',
            'slug' => 'batch-wave-replan-1',
            'status' => ProductionStatus::Planned,
            'sizing_mode' => SizingMode::OilWeight,
            'planned_quantity' => 10,
            'expected_units' => 100,
            'production_date' => '2026-03-01',
            'ready_date' => '2026-03-03',
            'organic' => true,
        ]);

        $second = Production::query()->create([
            'production_wave_id' => $wave->id,
            'production_line_id' => $line->id,
            'product_id' => $product->id,
            'formula_id' => $formula->id,
            'batch_number' => 'T94002',
            'slug' => 'batch-wave-replan-2',
            'status' => ProductionStatus::Confirmed,
            'sizing_mode' => SizingMode::OilWeight,
            'planned_quantity' => 10,
            'expected_units' => 100,
            'production_date' => '2026-03-02',
            'ready_date' => '2026-03-04',
            'organic' => true,
        ]);

        Livewire::test(ListProductionWaves::class)
            ->callAction(TestAction::make('replanWave')->table($wave), [
                'start_date' => '2026-03-09',
                'fallback_daily_capacity' => 4,
                'skip_weekends' => true,
                'skip_holidays' => true,
            ])
            ->assertHasNoErrors();

        expect($first->fresh()->production_date?->toDateString())->toBe('2026-03-09')
            ->and($second->fresh()->production_date?->toDateString())->toBe('2026-03-09');
    });

    it('recalculates wave planning when planned start date is edited on wave page', function () {
        $line = ProductionLine::factory()->soapLine()->create([
            'daily_batch_capacity' => 1,
        ]);

        $productType = ProductType::factory()->create();

        $template = TaskTemplate::query()->create([
            'name' => 'Template wave replan',
        ]);

        $template->productTypes()->attach($productType->id, [
            'is_default' => true,
        ]);

        $taskType = ProductionTaskType::factory()->create([
            'duration' => 60,
        ]);

        $template->taskTypes()->attach($taskType->id, [
            'sort_order' => 1,
            'offset_days' => 0,
            'skip_weekends' => true,
            'duration_override' => null,
        ]);

        $wave = ProductionWave::factory()->approved()->create([
            'planned_start_date' => '2026-03-01',
            'planned_end_date' => '2026-03-05',
        ]);

        $first = createWavePageProduction($wave, $line, ProductionStatus::Planned, 'T94101', '2026-03-01', $productType->id);
        $second = createWavePageProduction($wave, $line, ProductionStatus::Confirmed, 'T94102', '2026-03-02', $productType->id);

        expect($first->fresh()->productionTasks)->not->toBeEmpty()
            ->and($second->fresh()->productionTasks)->not->toBeEmpty();

        Livewire::test(EditProductionWave::class, [
            'record' => $wave->id,
        ])
            ->set('data.planned_start_date', '2026-03-09')
            ->set('data.planned_end_date', '2026-03-20')
            ->call('save')
            ->assertHasNoErrors();

        expect($first->fresh()->production_date?->toDateString())->toBe('2026-03-09')
            ->and($second->fresh()->production_date?->toDateString())->toBe('2026-03-10')
            ->and($wave->fresh()->planned_start_date?->toDateString())->toBe('2026-03-09')
            ->and($wave->fresh()->planned_end_date?->toDateString())->toBe('2026-03-10');
    });

});

describe('Procurement plan print route', function () {
    it('renders printable procurement plan document', function () {
        $wave = ProductionWave::factory()->create();
        $production = Production::factory()->create(['production_wave_id' => $wave->id]);
        $ingredient = Ingredient::factory()->create(['name' => 'Huile de Coco']);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 22.5,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $response = $this->get(route('production-waves.procurement-plan.print', $wave));

        $response
            ->assertOk()
            ->assertSee('Plan achats - '.$wave->name)
            ->assertSee('Huile de Coco');
    });
});
