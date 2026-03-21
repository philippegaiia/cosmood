<?php

use App\Enums\AllocationStatus;
use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Enums\ProductionOutputKind;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Filament\Resources\Production\ProductionResource;
use App\Filament\Resources\Production\ProductionResource\Pages\CreateProduction;
use App\Filament\Resources\Production\ProductionResource\Pages\EditProduction;
use App\Filament\Resources\Production\ProductionResource\Pages\ListProductions;
use App\Filament\Resources\Production\ProductionResource\Pages\ViewProduction;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionItemsRelationManager;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionOutputsRelationManager;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionQcChecksRelationManager;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionTasksRelationManager;
use App\Filament\Resources\Production\ProductionWaves\Pages\EditProductionWave;
use App\Models\Production\BatchSizePreset;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Production\ProductionOutput;
use App\Models\Production\ProductionQcCheck;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\Supply;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->assignRole(Role::findOrCreate('manager'));
});

describe('Production Model', function () {
    it('can be created with factory', function () {
        $production = Production::factory()->create();

        expect($production)
            ->toBeInstanceOf(Production::class)
            ->and($production->batch_number)->not->toBeEmpty()
            ->and($production->slug)->not->toBeEmpty();
    });

    it('has planned status by default', function () {
        $production = Production::factory()->create();

        expect($production->status)->toBe(ProductionStatus::Planned);
    });
});

describe('Production - Wave Relationship', function () {
    it('can be an orphan production', function () {
        $production = Production::factory()->orphan()->create();

        expect($production->isOrphan())->toBeTrue()
            ->and($production->production_wave_id)->toBeNull();
    });

    it('can belong to a wave', function () {
        $wave = ProductionWave::factory()->create();
        $production = Production::factory()->forWave($wave)->create();

        expect($production->isOrphan())->toBeFalse()
            ->and($production->production_wave_id)->toBe($wave->id)
            ->and($production->wave->id)->toBe($wave->id);
    });
});

describe('Production - Product Type', function () {
    it('can have product type defaults', function () {
        $productType = ProductType::factory()->soap()->create();
        $production = Production::factory()->withProductType($productType)->create();

        expect($production->product_type_id)->toBe($productType->id)
            ->and($production->sizing_mode)->toBe(SizingMode::OilWeight)
            ->and((float) $production->planned_quantity)->toBe(26.0)
            ->and($production->expected_units)->toBe(288);
    });

    it('can use batch size preset', function () {
        $productType = ProductType::factory()->create();
        $preset = BatchSizePreset::factory()->forProductType($productType)->standard()->create();

        $production = Production::factory()->create([
            'product_type_id' => $productType->id,
            'batch_size_preset_id' => $preset->id,
            'planned_quantity' => $preset->batch_size,
            'expected_units' => $preset->expected_units,
        ]);

        expect($production->batch_size_preset_id)->toBe($preset->id)
            ->and((float) $production->planned_quantity)->toBe(26.0);
    });
});

describe('Production - Masterbatch', function () {
    it('can be a masterbatch', function () {
        $production = Production::factory()->masterbatch()->create();

        expect($production->isMasterbatch())->toBeTrue()
            ->and($production->is_masterbatch)->toBeTrue()
            ->and($production->replaces_phase)->toBe('saponified_oils');
    });

    it('can use a masterbatch', function () {
        $masterbatch = Production::factory()->masterbatch()->finished()->create();
        $production = Production::factory()->usingMasterbatch($masterbatch)->create();

        expect($production->usesMasterbatch())->toBeTrue()
            ->and($production->masterbatch_lot_id)->toBe($masterbatch->id);
    });

    it('masterbatch can be used by multiple productions', function () {
        $masterbatch = Production::factory()->masterbatch()->finished()->create();
        Production::factory()->usingMasterbatch($masterbatch)->create();
        Production::factory()->usingMasterbatch($masterbatch)->create();

        expect($masterbatch->usedInProductions)->toHaveCount(2);
    });

    it('autofills manufactured ingredient from product when creating a masterbatch', function () {
        $this->actingAs($this->user);

        $ingredient = Ingredient::factory()->manufactured()->create();
        $productType = ProductType::factory()->soap()->create();
        $product = Product::factory()->create([
            'product_type_id' => $productType->id,
            'produced_ingredient_id' => $ingredient->id,
        ]);
        Formula::factory()->create([
            'product_id' => $product->id,
        ]);

        Livewire::test(CreateProduction::class)
            ->fillForm([
                'is_masterbatch' => true,
            ])
            ->fillForm([
                'product_id' => $product->id,
            ])
            ->assertSet('data.produced_ingredient_id', $ingredient->id);
    });
});

describe('Production Presence Locking', function () {
    it('blocks a second editor while another manager owns the edit page', function () {
        $firstUser = User::factory()->create();
        $firstUser->assignRole(Role::findOrCreate('manager'));

        $secondUser = User::factory()->create();
        $secondUser->assignRole(Role::findOrCreate('manager'));

        $production = Production::factory()->create();

        $this->actingAs($firstUser);

        Livewire::test(EditProduction::class, ['record' => $production->id])
            ->assertSet('hasForeignPresenceLock', false);

        $this->actingAs($secondUser);

        Livewire::test(EditProduction::class, ['record' => $production->id])
            ->assertSet('hasForeignPresenceLock', true)
            ->assertSee(__('presence-locking.blocked_title'))
            ->assertDontSee('PDF production');
    });

    it('blocks marking a production item as ordered from the view page while another manager edits the production', function () {
        $firstUser = User::factory()->create();
        $firstUser->assignRole(Role::findOrCreate('manager'));

        $secondUser = User::factory()->create();
        $secondUser->assignRole(Role::findOrCreate('manager'));

        $production = Production::factory()->planned()->create();
        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'is_order_marked' => false,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $this->actingAs($firstUser);

        Livewire::test(EditProduction::class, ['record' => $production->id])
            ->assertSet('hasForeignPresenceLock', false);

        $this->actingAs($secondUser);

        Livewire::test(ProductionItemsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => ViewProduction::class,
        ])
            ->callAction(TestAction::make('toggleOrderMark')->table($item))
            ->assertNotified(__('presence-locking.action_blocked_title'));

        expect($item->fresh()->is_order_marked)->toBeFalse()
            ->and($item->fresh()->procurement_status)->toBe(ProcurementStatus::NotOrdered);
    });

    it('blocks task replanning from the view page while another manager edits the production', function () {
        $firstUser = User::factory()->create();
        $firstUser->assignRole(Role::findOrCreate('manager'));

        $secondUser = User::factory()->create();
        $secondUser->assignRole(Role::findOrCreate('manager'));

        $production = Production::factory()->planned()->create();
        $task = ProductionTask::factory()->create([
            'production_id' => $production->id,
            'is_finished' => false,
            'scheduled_date' => now()->toDateString(),
            'is_manual_schedule' => false,
        ]);

        $originalScheduledDate = optional($task->scheduled_date)->toDateString();

        $this->actingAs($firstUser);

        Livewire::test(EditProduction::class, ['record' => $production->id])
            ->assertSet('hasForeignPresenceLock', false);

        $this->actingAs($secondUser);

        Livewire::test(ProductionTasksRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => ViewProduction::class,
        ])
            ->callAction(TestAction::make('reschedule')->table($task), [
                'scheduled_date' => now()->addDay()->toDateString(),
            ])
            ->assertNotified(__('presence-locking.action_blocked_title'));

        expect(optional($task->fresh()->scheduled_date)->toDateString())->toBe($originalScheduledDate);
    });

    it('shows an advisory banner on the production edit page when the linked wave is locked by another manager', function () {
        $planner = User::factory()->create();
        $planner->assignRole(Role::findOrCreate('manager'));

        $operator = User::factory()->create();
        $operator->assignRole(Role::findOrCreate('manager'));

        $wave = ProductionWave::factory()->create();
        $production = Production::factory()->forWave($wave)->create();

        $this->actingAs($planner);

        Livewire::test(EditProductionWave::class, ['record' => $wave->id])
            ->assertSet('hasForeignPresenceLock', false);

        $this->actingAs($operator);

        Livewire::test(EditProduction::class, ['record' => $production->id])
            ->assertSet('hasForeignPresenceLock', false)
            ->assertSet('hasForeignWavePresenceLockAdvisory', true)
            ->assertSee(__('presence-locking.parent_wave_advisory_title'))
            ->assertSee($planner->name);
    });

    it('shows an advisory banner on the production view page when the linked wave is locked by another manager', function () {
        $planner = User::factory()->create();
        $planner->assignRole(Role::findOrCreate('manager'));

        $viewer = User::factory()->create();
        $viewer->assignRole(Role::findOrCreate('manager'));

        $wave = ProductionWave::factory()->create();
        $production = Production::factory()->forWave($wave)->create();

        $this->actingAs($planner);

        Livewire::test(EditProductionWave::class, ['record' => $wave->id])
            ->assertSet('hasForeignPresenceLock', false);

        $this->actingAs($viewer);

        Livewire::test(ViewProduction::class, ['record' => $production->id])
            ->assertSet('hasForeignWavePresenceLockAdvisory', true)
            ->assertSee(__('presence-locking.parent_wave_advisory_title'))
            ->assertSee($planner->name);
    });
});

describe('Production - Status', function () {
    it('can have different statuses', function () {
        $planned = Production::factory()->planned()->create();
        $confirmed = Production::factory()->confirmed()->create();
        $inProgress = Production::factory()->inProgress()->create();
        $finished = Production::factory()->finished()->create();

        expect($planned->status)->toBe(ProductionStatus::Planned)
            ->and($confirmed->status)->toBe(ProductionStatus::Confirmed)
            ->and($inProgress->status)->toBe(ProductionStatus::Ongoing)
            ->and($finished->status)->toBe(ProductionStatus::Finished);
    });
});

describe('Production - Relationships', function () {
    it('belongs to a product', function () {
        $production = Production::factory()->create();

        expect($production->product)->not->toBeNull();
    });

    it('belongs to a formula', function () {
        $production = Production::factory()->create();

        expect($production->formula)->not->toBeNull();
    });

    it('registers relation manager tabs for items tasks and qc checks', function () {
        $relations = ProductionResource::getRelations();

        expect($relations)
            ->toContain(ProductionItemsRelationManager::class)
            ->toContain(ProductionOutputsRelationManager::class)
            ->toContain(ProductionTasksRelationManager::class)
            ->toContain(ProductionQcChecksRelationManager::class);
    });

    it('does not expose mark done action without entering a qc measurement', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->create();
        ProductionQcCheck::factory()->create([
            'production_id' => $production->id,
        ]);

        Livewire::test(ProductionQcChecksRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => EditProduction::class,
        ])->assertDontSee('Marquer fait');
    });

    it('shows generated task names in the production tasks relation manager', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->create();

        ProductionTask::factory()->create([
            'production_id' => $production->id,
            'task_template_item_id' => null,
            'production_task_type_id' => null,
            'name' => 'Conditionnement différé',
            'source' => 'template',
        ]);

        Livewire::test(ProductionTasksRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => EditProduction::class,
        ])->assertSee('Conditionnement différé')
            ->assertDontSee('Tâche manuelle');
    });

    it('renders the production outputs relation manager with the output target', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->create();

        ProductionOutput::factory()->create([
            'production_id' => $production->id,
            'kind' => ProductionOutputKind::MainProduct,
            'quantity' => $production->expected_units,
            'unit' => 'u',
        ]);

        Livewire::test(ProductionOutputsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => EditProduction::class,
        ])->assertSee('Sortie principale')
            ->assertSee($production->product->name);
    });

    it('shows outputs as read-only before production starts', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->confirmed()->create();

        Livewire::test(ProductionOutputsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => ViewProduction::class,
        ])->assertTableHeaderActionsExistInOrder([]);
    });

    it('lets operators prepare outputs during an ongoing production', function () {
        $operator = User::factory()->create();
        $operator->assignRole(Role::findOrCreate('operator'));
        $this->actingAs($operator);

        $production = Production::factory()->inProgress()->create();

        Livewire::test(ProductionOutputsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => ViewProduction::class,
        ])->assertTableHeaderActionsExistInOrder(['create']);
    });

    it('shows QC entry action only while production is ongoing', function () {
        $this->actingAs($this->user);

        $confirmedProduction = Production::factory()->confirmed()->create();
        $confirmedQcCheck = ProductionQcCheck::factory()->create([
            'production_id' => $confirmedProduction->id,
            'checked_at' => null,
            'value_number' => null,
            'value_boolean' => null,
            'value_text' => null,
        ]);

        Livewire::test(ProductionQcChecksRelationManager::class, [
            'ownerRecord' => $confirmedProduction,
            'pageClass' => ViewProduction::class,
        ])->assertTableActionHidden('recordResult', $confirmedQcCheck);

        $ongoingProduction = Production::factory()->inProgress()->create();
        $ongoingQcCheck = ProductionQcCheck::factory()->create([
            'production_id' => $ongoingProduction->id,
            'checked_at' => null,
            'value_number' => null,
            'value_boolean' => null,
            'value_text' => null,
        ]);

        Livewire::test(ProductionQcChecksRelationManager::class, [
            'ownerRecord' => $ongoingProduction,
            'pageClass' => ViewProduction::class,
        ])->assertTableActionVisible('recordResult', $ongoingQcCheck);
    });

    it('keeps QC reset as a planning-only action', function () {
        $operator = User::factory()->create();
        $operator->assignRole(Role::findOrCreate('operator'));

        $production = Production::factory()->inProgress()->create();
        $qcCheck = ProductionQcCheck::factory()->passed()->create([
            'production_id' => $production->id,
        ]);

        $this->actingAs($operator);

        Livewire::test(ProductionQcChecksRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => ViewProduction::class,
        ])->assertTableActionHidden('markUndone', $qcCheck);

        $this->actingAs($this->user);

        Livewire::test(ProductionQcChecksRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => ViewProduction::class,
        ])->assertTableActionVisible('markUndone', $qcCheck->fresh());
    });

    it('shows total product cost summary in production items relation manager', function () {
        $production = Production::factory()->create([
            'planned_quantity' => 100,
        ]);

        $ingredientA = Ingredient::factory()->create([
            'price' => 5,
        ]);
        $listingA = SupplierListing::factory()->create([
            'ingredient_id' => $ingredientA->id,
            'price' => 6,
        ]);
        $supplyA = Supply::factory()->create([
            'supplier_listing_id' => $listingA->id,
            'unit_price' => 9,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredientA->id,
            'supplier_listing_id' => $listingA->id,
            'supply_id' => $supplyA->id,
            'percentage_of_oils' => 10,
        ]);

        $ingredientB = Ingredient::factory()->create([
            'price' => 4,
        ]);
        $listingB = SupplierListing::factory()->create([
            'ingredient_id' => $ingredientB->id,
            'price' => 6,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredientB->id,
            'supplier_listing_id' => $listingB->id,
            'supply_id' => null,
            'percentage_of_oils' => 5,
        ]);

        Livewire::test(ProductionItemsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => EditProduction::class,
        ])->assertTableColumnSummarySet('product_cost', 'total_cost', 120.0);
    });

    it('keeps imported supply traceability visible in edit form repeater state', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
        ]);
        $supply = Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'batch_number' => 'LOT-UI-001',
            'is_in_stock' => true,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'supply_id' => $supply->id,
            'supply_batch_number' => 'LOT-UI-001',
            'is_supplied' => true,
        ]);

        Livewire::test(EditProduction::class, [
            'record' => $production->id,
        ]);

        Livewire::test(ProductionItemsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => EditProduction::class,
        ])->assertSee('LOT-UI-001');
    });

    it('shows active allocation lot in production items table even when item traceability fields are stale', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
        ]);
        $supply = Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'batch_number' => 'LOT-ACTIVE-001',
            'is_in_stock' => true,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'supply_id' => null,
            'supply_batch_number' => null,
            'is_supplied' => false,
        ]);

        ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 5,
            'status' => 'reserved',
            'reserved_at' => now(),
        ]);

        Livewire::test(ProductionItemsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => EditProduction::class,
        ])->assertSee('LOT-ACTIVE-001');
    });

    it('shows and toggles commande passee from production items tab', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->create();
        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'is_order_marked' => false,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        Livewire::test(ProductionItemsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => EditProduction::class,
        ])
            ->assertSee('Non')
            ->callAction(TestAction::make('toggleOrderMark')->table($item))
            ->assertHasNoErrors()
            ->assertSee('Oui')
            ->callAction(TestAction::make('toggleOrderMark')->table($item))
            ->assertHasNoErrors();

        expect($item->fresh()->is_order_marked)->toBeFalse()
            ->and($item->fresh()->procurement_status)->toBe(ProcurementStatus::NotOrdered);
    });

    it('shows an item as pris en charge from the production items tab when covered by stock allocation', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->create();
        $supply = Supply::factory()->inStock(20)->create([
            'batch_number' => 'LOT-COVERED-001',
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'is_order_marked' => false,
            'procurement_status' => ProcurementStatus::Received,
            'allocation_status' => AllocationStatus::Allocated,
            'required_quantity' => 10,
        ]);

        ProductionItemAllocation::factory()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 10,
            'status' => 'reserved',
            'reserved_at' => now(),
        ]);

        Livewire::test(ProductionItemsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => EditProduction::class,
        ])
            ->assertSee('Pris en charge')
            ->assertSee('Oui');
    });

    it('keeps the original product when editing a production', function () {
        $this->actingAs($this->user);

        $originalProduct = Product::factory()->create();
        Formula::factory()->create([
            'product_id' => $originalProduct->id,
        ]);

        $replacementProduct = Product::factory()->create();
        Formula::factory()->create([
            'product_id' => $replacementProduct->id,
        ]);

        $production = Production::factory()->create([
            'product_id' => $originalProduct->id,
            'formula_id' => $originalProduct->formulas()->value('formulas.id'),
        ]);

        Livewire::test(EditProduction::class, [
            'record' => $production->id,
        ])
            ->set('data.product_id', $replacementProduct->id)
            ->call('save');

        expect($production->fresh()->product_id)->toBe($originalProduct->id);
    });

    it('lets operators open the production view page', function () {
        $this->disableAuthorizationBypass();

        $operatorRole = Role::findOrCreate('operator');
        $operatorRole->syncPermissions([
            Permission::findOrCreate('ViewAny:Production'),
            Permission::findOrCreate('View:Production'),
            Permission::findOrCreate('ViewAny:ProductionTask'),
        ]);

        $operator = User::factory()->create();
        $operator->assignRole($operatorRole);

        $production = Production::factory()->create();

        $this->actingAs($operator);

        expect(ProductionResource::canViewAny())->toBeTrue()
            ->and(ProductionResource::canView($production))->toBeTrue();

        $response = $this->get(ProductionResource::getUrl('view', ['record' => $production]));

        $response->assertOk();
    });

    it('shows an execution status summary on the production view page', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->planned()->create();

        $response = $this->get(ProductionResource::getUrl('view', ['record' => $production]));

        $response->assertOk()
            ->assertSee('Exécution')
            ->assertSee('Statut')
            ->assertSee(ProductionStatus::Planned->getLabel());
    });

    it('hides task completion actions before the production starts', function () {
        $operator = User::factory()->create();
        $operator->assignRole(Role::findOrCreate('operator'));
        $this->actingAs($operator);

        $production = Production::factory()->confirmed()->create();
        $task = ProductionTask::factory()->create([
            'production_id' => $production->id,
            'scheduled_date' => now()->toDateString(),
            'date' => now()->toDateString(),
            'is_finished' => false,
            'cancelled_at' => null,
            'sequence_order' => 1,
            'source' => 'template',
        ]);

        Livewire::test(ProductionTasksRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => ViewProduction::class,
        ])
            ->assertTableActionHidden('finish', $task)
            ->assertTableActionHidden('force_finish', $task);
    });

    it('hides task completion actions for future-dated tasks', function () {
        $operator = User::factory()->create();
        $operator->assignRole(Role::findOrCreate('operator'));
        $this->actingAs($operator);

        $production = Production::factory()->inProgress()->create();
        $task = ProductionTask::factory()->create([
            'production_id' => $production->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'date' => now()->addDay()->toDateString(),
            'is_finished' => false,
            'cancelled_at' => null,
            'sequence_order' => 1,
            'source' => 'template',
        ]);

        Livewire::test(ProductionTasksRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => ViewProduction::class,
        ])
            ->assertTableActionHidden('finish', $task)
            ->assertTableActionHidden('force_finish', $task);
    });

    it('keeps the task completion action available for ongoing tasks due today', function () {
        $operator = User::factory()->create();
        $operator->assignRole(Role::findOrCreate('operator'));
        $this->actingAs($operator);

        $production = Production::factory()->inProgress()->create();
        $task = ProductionTask::factory()->create([
            'production_id' => $production->id,
            'scheduled_date' => now()->toDateString(),
            'date' => now()->toDateString(),
            'is_finished' => false,
            'cancelled_at' => null,
            'sequence_order' => 1,
            'source' => 'template',
        ]);

        Livewire::test(ProductionTasksRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => ViewProduction::class,
        ])
            ->assertTableActionVisible('finish', $task)
            ->assertTableActionHidden('force_finish', $task);
    });

    it('hides procurement mark action from operators in production items view', function () {
        $operator = User::factory()->create();
        $operator->assignRole(Role::findOrCreate('operator'));
        $this->actingAs($operator);

        $production = Production::factory()->planned()->create();
        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'is_order_marked' => false,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        Livewire::test(ProductionItemsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => ViewProduction::class,
        ])->assertTableActionHidden('toggleOrderMark', $item);
    });

    it('refreshes permanent batch number in edit form after moving to ongoing', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->confirmed()->create([
            'permanent_batch_number' => null,
        ]);

        Livewire::test(EditProduction::class, [
            'record' => $production->id,
        ])
            ->set('data.status', ProductionStatus::Ongoing->value)
            ->call('save')
            ->call('save')
            ->assertSet('data.permanent_batch_number', '00001');

        expect($production->fresh()->permanent_batch_number)->toBe('00001');
    });

    it('blocks transition to ongoing with a notification when items are not fully allocated', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->confirmed()->create();

        $production->productionItems()->delete();

        $ingredient = Ingredient::factory()->create();

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 12,
            'procurement_status' => ProcurementStatus::Ordered,
            'supply_id' => null,
        ]);

        Livewire::test(EditProduction::class, [
            'record' => $production->id,
        ])
            ->set('data.status', ProductionStatus::Ongoing->value)
            ->call('save')
            ->assertNotified('Allocations incomplètes');

        expect($production->fresh()->status)->toBe(ProductionStatus::Confirmed);
    });

    it('allows transition to ongoing when only packaging items are not fully allocated', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->confirmed()->create([
            'permanent_batch_number' => null,
        ]);

        $production->productionItems()->delete();

        $fabricationIngredient = Ingredient::factory()->create();
        $packagingIngredient = Ingredient::factory()->create();
        $supply = Supply::factory()->inStock(100)->create();

        $allocatedItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $fabricationIngredient->id,
            'required_quantity' => 12,
            'phase' => Phases::Additives->value,
            'procurement_status' => ProcurementStatus::Ordered,
            'supply_id' => $supply->id,
        ]);

        ProductionItemAllocation::query()->create([
            'production_item_id' => $allocatedItem->id,
            'supply_id' => $supply->id,
            'quantity' => 12,
            'status' => 'reserved',
            'reserved_at' => now(),
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $packagingIngredient->id,
            'required_quantity' => 144,
            'phase' => Phases::Packaging->value,
            'procurement_status' => ProcurementStatus::Ordered,
            'supply_id' => null,
        ]);

        Livewire::test(EditProduction::class, [
            'record' => $production->id,
        ])
            ->set('data.status', ProductionStatus::Ongoing->value)
            ->call('save')
            ->call('save')
            ->assertNotified(__('Packaging à suivre'));

        expect($production->fresh()->status)->toBe(ProductionStatus::Ongoing);
    });

    it('blocks transition to finished when required items have no selected lot', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->inProgress()->create();

        $production->productionItems()->delete();

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'supply_id' => null,
            'supplier_listing_id' => null,
            'supply_batch_number' => null,
        ]);

        Livewire::test(EditProduction::class, [
            'record' => $production->id,
        ])
            ->set('data.status', ProductionStatus::Finished->value)
            ->call('save')
            ->assertNotified('Lots supply manquants');

        expect($production->fresh()->status)->toBe(ProductionStatus::Ongoing);
    });

    it('blocks transition to finished when active tasks are incomplete', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->inProgress()->create();

        $production->productionItems()->delete();
        $production->productionTasks()->delete();
        $production->productionQcChecks()->delete();

        $ingredient = Ingredient::factory()->create();
        $supply = Supply::factory()->inStock(100)->create();

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 10,
            'procurement_status' => ProcurementStatus::NotOrdered,
            'supply_id' => $supply->id,
        ]);

        ProductionTask::factory()->create([
            'production_id' => $production->id,
            'name' => 'Conditionnement',
            'is_finished' => false,
            'cancelled_at' => null,
        ]);

        ProductionQcCheck::factory()->passed()->create([
            'production_id' => $production->id,
            'required' => true,
        ]);

        Livewire::test(EditProduction::class, [
            'record' => $production->id,
        ])
            ->set('data.status', ProductionStatus::Finished->value)
            ->call('save')
            ->assertNotified('Tâches incomplètes');

        expect($production->fresh()->status)->toBe(ProductionStatus::Ongoing);
    });

    it('blocks transition to finished when outputs are missing', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->inProgress()->create();

        $production->productionItems()->delete();
        $production->productionTasks()->delete();
        $production->productionQcChecks()->delete();

        $ingredient = Ingredient::factory()->create();
        $supply = Supply::factory()->inStock(100)->create();

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 10,
            'procurement_status' => ProcurementStatus::NotOrdered,
            'supply_id' => $supply->id,
        ]);

        ProductionTask::factory()->finished()->create([
            'production_id' => $production->id,
            'cancelled_at' => null,
        ]);

        ProductionQcCheck::factory()->passed()->create([
            'production_id' => $production->id,
            'required' => true,
        ]);

        Livewire::test(EditProduction::class, [
            'record' => $production->id,
        ])
            ->set('data.status', ProductionStatus::Finished->value)
            ->call('save')
            ->assertNotified('Sorties à compléter');

        expect($production->fresh()->status)->toBe(ProductionStatus::Ongoing);
    });

    it('blocks save when soap production has saponified lines and total is not 100 percent', function () {
        $this->actingAs($this->user);

        $soapType = ProductType::factory()->create([
            'name' => 'Savon solide',
            'slug' => 'savon-solide',
        ]);
        $product = Product::factory()->create([
            'product_type_id' => $soapType->id,
        ]);
        $formula = Formula::factory()->create([
            'product_id' => $product->id,
            'is_soap' => true,
        ]);

        $production = Production::factory()->create([
            'product_id' => $product->id,
            'formula_id' => $formula->id,
            'product_type_id' => $soapType->id,
        ]);

        $production->productionItems()->delete();

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'phase' => Phases::Saponification->value,
            'percentage_of_oils' => 40,
        ]);
        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'phase' => Phases::Saponification->value,
            'percentage_of_oils' => 30,
        ]);

        Livewire::test(EditProduction::class, [
            'record' => $production->id,
        ])
            ->fillForm([
                'notes' => 'Save should be blocked',
            ])
            ->call('save')
            ->assertNotified('Total saponifié invalide');

        expect($production->fresh()->notes)->not->toBe('Save should be blocked');
    });

    it('does not ask confirmation when formula control is disabled, even with saponification lines', function () {
        $this->actingAs($this->user);

        $balmType = ProductType::factory()->create([
            'name' => 'Baume',
            'slug' => 'baume',
        ]);
        $product = Product::factory()->create([
            'product_type_id' => $balmType->id,
        ]);
        $formula = Formula::factory()->create([
            'product_id' => $product->id,
            'is_soap' => false,
        ]);

        $production = Production::factory()->create([
            'product_id' => $product->id,
            'formula_id' => $formula->id,
            'product_type_id' => $balmType->id,
        ]);

        $production->productionItems()->delete();

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'phase' => Phases::Saponification->value,
            'percentage_of_oils' => 40,
        ]);
        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'phase' => Phases::Saponification->value,
            'percentage_of_oils' => 30,
        ]);

        Livewire::test(EditProduction::class, [
            'record' => $production->id,
        ])
            ->fillForm([
                'notes' => 'Save without confirmation',
            ])
            ->call('save')
            ->assertNotNotified('Total saponifié invalide')
            ->assertHasNoFormErrors();

        expect($production->fresh()->notes)->toBe('Save without confirmation');
    });

    it('does not block save for soap formula when there are no saponified lines', function () {
        $this->actingAs($this->user);

        $balmType = ProductType::factory()->create([
            'name' => 'Baume',
            'slug' => 'baume',
        ]);
        $product = Product::factory()->create([
            'product_type_id' => $balmType->id,
        ]);
        $formula = Formula::factory()->create([
            'product_id' => $product->id,
            'is_soap' => true,
        ]);

        $production = Production::factory()->create([
            'product_id' => $product->id,
            'formula_id' => $formula->id,
            'product_type_id' => $balmType->id,
        ]);

        $production->productionItems()->delete();

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'phase' => Phases::Packaging->value,
            'percentage_of_oils' => 1,
        ]);

        Livewire::test(EditProduction::class, [
            'record' => $production->id,
        ])
            ->fillForm([
                'notes' => 'Formula soap without saponified lines',
            ])
            ->call('save')
            ->assertNotNotified('Total saponifié invalide')
            ->assertHasNoFormErrors();

        expect($production->fresh()->notes)->toBe('Formula soap without saponified lines');
    });
});

describe('Production deletion contract', function () {
    it('permanently deletes pre-start productions', function () {
        $production = Production::factory()->confirmed()->create();

        $production->delete();

        expect(Production::query()->whereKey($production->id)->exists())->toBeFalse();
    });

    it('keeps ongoing productions undeletable', function () {
        $production = Production::factory()->inProgress()->create();

        expect(fn () => $production->delete())
            ->toThrow(InvalidArgumentException::class, 'Seules les productions planifiées ou confirmées peuvent être supprimées définitivement.');
    });
});

describe('Production sheet print route', function () {
    it('renders printable production sheet with items tasks and qc', function () {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'name' => 'Savon Tres Doux',
        ]);

        $production = Production::factory()->create([
            'product_id' => $product->id,
            'batch_number' => 'B-PRINT-001',
            'permanent_batch_number' => '00042',
            'actual_units' => 243,
            'notes' => 'Verifier visuellement la couleur avant emballage.',
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
        ]);

        ProductionTask::factory()->create([
            'production_id' => $production->id,
            'name' => 'Melange cuve',
        ]);

        ProductionQcCheck::factory()->create([
            'production_id' => $production->id,
            'label' => 'Poids net',
        ]);

        $response = $this->get(route('productions.print-sheet', $production));

        $response
            ->assertOk()
            ->assertSee('Fiche Production - 00042 - Savon Tres Doux')
            ->assertSee('Batch planning:')
            ->assertSee('B-PRINT-001')
            ->assertSee('Unites produites (reelles):')
            ->assertSee('243')
            ->assertSee('Melange cuve')
            ->assertSee('Poids net')
            ->assertDontSee('Prix ref (EUR/kg)')
            ->assertDontSee('Cout estime (EUR)')
            ->assertDontSee('Non fait')
            ->assertDontSee('....................................')
            ->assertSee('Commentaires / Observations')
            ->assertSee('Verifier visuellement la couleur avant emballage.');
    });

    it('exports production sheet as pdf', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->create([
            'batch_number' => 'B-PDF-001',
        ]);

        $response = $this->get(route('productions.sheet-pdf', $production));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    });

    it('renders follow sheet with six secondary crate labels in two rows', function () {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'name' => 'Baume Mains',
        ]);

        $production = Production::factory()->create([
            'product_id' => $product->id,
            'batch_number' => 'T01234',
            'permanent_batch_number' => '00111',
            'expected_units' => 320,
            'production_date' => '2026-02-25',
        ]);

        $response = $this->get(route('productions.follow-sheet', $production));

        $response
            ->assertOk()
            ->assertSee('Fiche de suivi - Baume Mains - 00111')
            ->assertSee('Date de production: 25/02/2026 - Quantité attendue: 320 - Quantité réelle:')
            ->assertSee('Référence planning: T01234');

        $content = $response->getContent();

        expect(substr_count($content, 'Baume Mains - 00111'))->toBeGreaterThanOrEqual(7);
    });

    it('renders bulk document launcher for selected productions', function () {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'name' => 'Savon Atelier',
        ]);

        $firstProduction = Production::factory()->create([
            'product_id' => $product->id,
            'batch_number' => 'T20001',
            'permanent_batch_number' => '00201',
        ]);

        $secondProduction = Production::factory()->create([
            'product_id' => $product->id,
            'batch_number' => 'T20002',
            'permanent_batch_number' => '00202',
        ]);

        $response = $this->get(route('productions.bulk-documents', [
            'ids' => $firstProduction->id.','.$secondProduction->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('Impression groupée - Productions')
            ->assertSee('00201')
            ->assertSee('00202')
            ->assertSee('Fiche production')
            ->assertSee('Fiche suivi');
    });

    it('shows collapsed masterbatch line and traceability details in production sheet', function () {
        $this->actingAs($this->user);

        $ingredient = Ingredient::factory()->create([
            'name' => 'Huile de grignon olive',
        ]);
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
        ]);
        $supply = Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'batch_number' => 'LOT-MB-TRACE',
            'order_ref' => 'PO-MB-001',
        ]);

        $masterbatch = Production::factory()->masterbatch()->finished()->create([
            'batch_number' => 'MB01',
            'replaces_phase' => 'saponified_oils',
        ]);

        $masterbatchItem = ProductionItem::factory()->create([
            'production_id' => $masterbatch->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'supply_id' => $supply->id,
            'supply_batch_number' => 'LOT-MB-TRACE',
            'phase' => '10',
            'percentage_of_oils' => 60,
            'is_supplied' => true,
        ]);

        ProductionItemAllocation::factory()->create([
            'production_item_id' => $masterbatchItem->id,
            'supply_id' => $supply->id,
            'quantity' => 12,
            'status' => 'reserved',
        ]);

        $production = Production::factory()->usingMasterbatch($masterbatch)->create([
            'batch_number' => 'S01',
            'planned_quantity' => 26,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'phase' => '10',
            'percentage_of_oils' => 60,
        ]);

        $response = $this->get(route('productions.print-sheet', $production));

        $response
            ->assertOk()
            ->assertSee('Masterbatch MB01')
            ->assertSee('Traçabilité masterbatch MB01')
            ->assertSee('LOT-MB-TRACE');
    });
});

describe('Production list table actions', function () {
    it('confirms a planned production from list table action', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->planned()->create();

        Livewire::test(ListProductions::class)
            ->callAction(TestAction::make('confirmProduction')->table($production))
            ->assertHasNoErrors();

        expect($production->fresh()->status)->toBe(ProductionStatus::Confirmed);
    });

    it('shows bulk confirm action on production list', function () {
        $this->actingAs($this->user);

        Production::factory()->count(2)->planned()->create();

        Livewire::test(ListProductions::class)
            ->assertSee('Confirmer sélection');
    });

    it('duplicates a production from list table action', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->confirmed()->create([
            'batch_number' => 'B5402',
            'slug' => 'b5402',
        ]);

        Livewire::test(ListProductions::class)
            ->callAction(TestAction::make('duplicate')->table($production))
            ->assertHasNoErrors();

        $duplicate = Production::query()
            ->where('id', '!=', $production->id)
            ->latest('id')
            ->first();

        expect($duplicate)->not->toBeNull()
            ->and($duplicate->status)->toBe(ProductionStatus::Planned)
            ->and($duplicate->batch_number)->toMatch('/^T\d{5}$/')
            ->and($duplicate->batch_number)->not->toBe($production->batch_number);
    });

    it('duplicates increment with sequential planning references', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->confirmed()->create([
            'batch_number' => 'B7000',
            'slug' => 'b7000',
        ]);

        $list = Livewire::test(ListProductions::class);

        $list->callAction(TestAction::make('duplicate')->table($production))->assertHasNoErrors();

        $firstDuplicate = Production::query()
            ->where('id', '!=', $production->id)
            ->latest('id')
            ->first();

        expect($firstDuplicate)->not->toBeNull();

        $list->callAction(TestAction::make('duplicate')->table($firstDuplicate))->assertHasNoErrors();

        $secondDuplicate = Production::query()
            ->whereNotIn('id', [$production->id, $firstDuplicate->id])
            ->latest('id')
            ->first();

        expect($secondDuplicate)->not->toBeNull()
            ->and($firstDuplicate->batch_number)->toMatch('/^T\d{5}$/')
            ->and($secondDuplicate->batch_number)->toMatch('/^T\d{5}$/');

        preg_match('/^T(\d{5})$/', $firstDuplicate->batch_number, $firstMatches);
        preg_match('/^T(\d{5})$/', $secondDuplicate->batch_number, $secondMatches);

        expect((int) ($secondMatches[1] ?? 0))->toBe((int) ($firstMatches[1] ?? 0) + 1);
    });

    it('shows fabrication readiness on the production list when only packaging is missing', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->confirmed()->create();
        $production->productionItems()->delete();

        $fabricationIngredient = Ingredient::factory()->create();
        $packagingIngredient = Ingredient::factory()->create(['is_packaging' => true]);
        $supply = Supply::factory()->inStock(100)->create();

        $allocatedItem = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $fabricationIngredient->id,
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
            'required_quantity' => 96,
            'phase' => Phases::Packaging->value,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        Livewire::test(ListProductions::class)
            ->assertSee('Prêt à démarrer')
            ->assertSee('Prête');

        expect($production->fresh()->getFabricationReadinessLabel())->toBe('Prête');
    });

    it('marks orphan production items as ordered from the production list', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->orphan()->planned()->create();
        $ingredient = Ingredient::factory()->create();

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'procurement_status' => ProcurementStatus::NotOrdered,
            'is_order_marked' => false,
            'required_quantity' => 12.0,
        ]);

        Livewire::test(ListProductions::class)
            ->callAction(TestAction::make('markOrphanItemsOrdered')->table($production), [
                'ingredient_ids' => [$ingredient->id],
            ])
            ->assertHasNoErrors();

        expect($item->fresh()->is_order_marked)->toBeTrue()
            ->and($item->fresh()->procurement_status)->toBe(ProcurementStatus::Ordered);
    });

    it('shows orphan production stock allocation action on the production list', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->orphan()->planned()->create();
        $ingredient = Ingredient::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
        ]);

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'procurement_status' => ProcurementStatus::NotOrdered,
            'allocation_status' => AllocationStatus::Unassigned,
            'required_quantity' => 40.0,
        ]);

        $supply = Supply::factory()->inStock(50.0)->create([
            'supplier_listing_id' => $listing->id,
        ]);

        Livewire::test(ListProductions::class)
            ->assertTableActionVisible('allocateOrphanIngredientStock', $production);

        expect($item->fresh()->allocation_status)->toBe(AllocationStatus::Unassigned)
            ->and((float) $item->fresh()->getTotalAllocatedQuantity())->toBe(0.0)
            ->and((float) $supply->fresh()->getAllocatedQuantity())->toBe(0.0);
    });
});

describe('Production create form validations', function () {
    it('auto-generates a short planning batch reference when empty', function () {
        $this->actingAs($this->user);

        $productType = ProductType::factory()->soap()->create();
        $product = Product::factory()->create([
            'product_type_id' => $productType->id,
        ]);
        $formula = Formula::factory()->create([
            'product_id' => $product->id,
        ]);

        Livewire::test(CreateProduction::class)
            ->fillForm([
                'product_id' => $product->id,
                'formula_id' => $formula->id,
                'product_type_id' => $productType->id,
                'sizing_mode' => SizingMode::OilWeight->value,
                'planned_quantity' => 26,
                'expected_units' => 288,
                'production_date' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Production::query()->latest('id')->first();

        expect($created)->not->toBeNull()
            ->and($created->batch_number)->toMatch('/^T\d{5}$/');
    });

    it('does not allow production date before wave start date', function () {
        $this->actingAs($this->user);

        $wave = ProductionWave::factory()->create([
            'planned_start_date' => now()->addDays(5)->toDateString(),
        ]);
        $productType = ProductType::factory()->soap()->create();
        $product = Product::factory()->create([
            'product_type_id' => $productType->id,
        ]);
        $formula = Formula::factory()->create([
            'product_id' => $product->id,
        ]);

        Livewire::test(CreateProduction::class)
            ->fillForm([
                'production_wave_id' => $wave->id,
                'batch_number' => 'B-WAVE-VAL-001',
                'product_id' => $product->id,
                'formula_id' => $formula->id,
                'product_type_id' => $productType->id,
                'sizing_mode' => SizingMode::OilWeight->value,
                'planned_quantity' => 26,
                'expected_units' => 288,
                'status' => ProductionStatus::Planned->value,
                'production_date' => now()->addDays(2)->toDateString(),
            ])
            ->call('create')
            ->assertHasFormErrors([
                'production_date' => 'after_or_equal',
            ]);
    });
});
