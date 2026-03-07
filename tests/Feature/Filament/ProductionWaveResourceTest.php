<?php

use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Enums\WaveStatus;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionTaskType;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use App\Models\Production\TaskTemplate;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
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
        $relations = \App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource::getRelations();

        expect($relations)->toContain(\App\Filament\Resources\Production\ProductionWaves\RelationManagers\ProductionsRelationManager::class);
    });

    it('shows procurement signal and manual order marker in related productions tab', function () {
        $wave = ProductionWave::factory()->approved()->create();
        $production = Production::factory()->forWave($wave)->confirmed()->create();

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'is_order_marked' => true,
            'procurement_status' => ProcurementStatus::Ordered,
        ]);

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\RelationManagers\ProductionsRelationManager::class, [
            'ownerRecord' => $wave,
            'pageClass' => \App\Filament\Resources\Production\ProductionWaves\Pages\EditProductionWave::class,
        ])
            ->assertSee('Commandé')
            ->assertSee('Oui (1)');
    });

    it('shows productions relation manager on edit wave page', function () {
        $wave = ProductionWave::factory()->create();
        Production::factory()->forWave($wave)->create();

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\EditProductionWave::class, [
            'record' => $wave->id,
        ])->assertSeeLivewire(\App\Filament\Resources\Production\ProductionWaves\RelationManagers\ProductionsRelationManager::class);
    });

    it('shows the approvisionnement tab on edit wave page', function () {
        $wave = ProductionWave::factory()->create();

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\EditProductionWave::class, [
            'record' => $wave->id,
        ])->assertSee('Approvisionnement');
    });

    it('lists only productions attached to the current wave in relation manager', function () {
        $wave = ProductionWave::factory()->create();
        $otherWave = ProductionWave::factory()->create();

        $waveProductions = Production::factory()->count(2)->forWave($wave)->create();
        $otherWaveProduction = Production::factory()->forWave($otherWave)->create();

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\RelationManagers\ProductionsRelationManager::class, [
            'ownerRecord' => $wave,
            'pageClass' => \App\Filament\Resources\Production\ProductionWaves\Pages\EditProductionWave::class,
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

describe('ProductionWave - Soft Deletes', function () {
    it('can be soft deleted', function () {
        $wave = ProductionWave::factory()->create();

        $wave->delete();

        expect($wave->fresh()->deleted_at)->not->toBeNull();
    });

    it('can be restored', function () {
        $wave = ProductionWave::factory()->create();
        $wave->delete();

        $wave->restore();

        expect($wave->fresh()->deleted_at)->toBeNull();
    });
});

describe('ProductionWaveResource - table actions', function () {
    it('creates wave as draft without requiring planned end date on create page', function () {
        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\CreateProductionWave::class)
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

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
            ->callAction(TestAction::make('approve')->table($wave))
            ->assertHasNoErrors();

        expect($wave->fresh()->status)->toBe(WaveStatus::Approved);
    });

    it('approves a draft wave from list action', function () {
        $wave = ProductionWave::factory()->draft()->create();

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
            ->callAction(TestAction::make('approve')->table($wave))
            ->assertHasNoErrors();

        expect($wave->fresh()->status)->toBe(WaveStatus::Approved);
    });

    it('starts an approved wave from list action', function () {
        $wave = ProductionWave::factory()->approved()->create();

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
            ->callAction(TestAction::make('start')->table($wave))
            ->assertHasNoErrors();

        expect($wave->fresh()->status)->toBe(WaveStatus::InProgress);
    });

    it('shows procurement coverage legend action on list page', function () {
        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
            ->assertSee('Légende couverture');
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

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
            ->callAction(TestAction::make('markWaveIngredientOrdered')->table($wave), [
                'ingredient_ids' => [(string) $ingredientMarked->id],
            ])
            ->assertHasNoErrors();

        expect($itemToMark->fresh()->is_order_marked)->toBeTrue()
            ->and($itemToMark->fresh()->procurement_status)->toBe(ProcurementStatus::Ordered)
            ->and($itemAlreadyOrdered->fresh()->is_order_marked)->toBeFalse()
            ->and($itemUntouched->fresh()->is_order_marked)->toBeFalse();
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

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
            ->callAction(TestAction::make('procurementPlan')->table($wave))
            ->assertHasNoErrors();
    });

    it('opens procurement plan action even without requirements', function () {
        $wave = ProductionWave::factory()->approved()->create();

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
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

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
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

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
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

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
            ->assertSee('Couverture appro');

        expect($wave->fresh()->getCoverageSignalLabel())->toBe('Partielle');
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

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
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

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\EditProductionWave::class, [
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
