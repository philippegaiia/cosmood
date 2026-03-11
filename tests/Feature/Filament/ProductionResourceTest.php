<?php

use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Enums\ProductionOutputKind;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
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

beforeEach(function () {
    $this->user = User::factory()->create();
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\CreateProduction::class)
            ->fillForm([
                'is_masterbatch' => true,
            ])
            ->fillForm([
                'product_id' => $product->id,
            ])
            ->assertSet('data.produced_ingredient_id', $ingredient->id);
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
        $relations = \App\Filament\Resources\Production\ProductionResource::getRelations();

        expect($relations)
            ->toContain(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionItemsRelationManager::class)
            ->toContain(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionOutputsRelationManager::class)
            ->toContain(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionTasksRelationManager::class)
            ->toContain(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionQcChecksRelationManager::class);
    });

    it('does not expose mark done action without entering a qc measurement', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->create();
        ProductionQcCheck::factory()->create([
            'production_id' => $production->id,
        ]);

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionQcChecksRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => \App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class,
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionTasksRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => \App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class,
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionOutputsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => \App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class,
        ])->assertSee('Sortie principale')
            ->assertSee($production->product->name);
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionItemsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => \App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class,
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class, [
            'record' => $production->id,
        ]);

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionItemsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => \App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class,
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionItemsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => \App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class,
        ])->assertSee('LOT-ACTIVE-001');
    });

    it('shows and toggles commande passee from production items tab', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->create();
        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'is_order_marked' => false,
            'procurement_status' => \App\Enums\ProcurementStatus::NotOrdered,
        ]);

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionItemsRelationManager::class, [
            'ownerRecord' => $production,
            'pageClass' => \App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class,
        ])
            ->assertSee('Non')
            ->callAction(TestAction::make('toggleOrderMark')->table($item))
            ->assertHasNoErrors()
            ->assertSee('Oui')
            ->callAction(TestAction::make('toggleOrderMark')->table($item))
            ->assertHasNoErrors();

        expect($item->fresh()->is_order_marked)->toBeFalse()
            ->and($item->fresh()->procurement_status)->toBe(\App\Enums\ProcurementStatus::NotOrdered);
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class, [
            'record' => $production->id,
        ])
            ->set('data.product_id', $replacementProduct->id)
            ->call('save');

        expect($production->fresh()->product_id)->toBe($originalProduct->id);
    });

    it('refreshes permanent batch number in edit form after moving to ongoing', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->confirmed()->create([
            'permanent_batch_number' => null,
        ]);

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class, [
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class, [
            'record' => $production->id,
        ])
            ->set('data.status', ProductionStatus::Ongoing->value)
            ->call('save')
            ->assertNotified('Allocations incomplètes');

        expect($production->fresh()->status)->toBe(ProductionStatus::Confirmed);
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class, [
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class, [
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class, [
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class, [
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class, [
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class, [
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

describe('Production - Soft Deletes', function () {
    it('can be soft deleted', function () {
        $production = Production::factory()->create();

        $production->delete();

        expect($production->fresh()->deleted_at)->not->toBeNull();
    });

    it('can be restored', function () {
        $production = Production::factory()->create();
        $production->delete();

        $production->restore();

        expect($production->fresh()->deleted_at)->toBeNull();
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\ListProductions::class)
            ->callAction(TestAction::make('confirmProduction')->table($production))
            ->assertHasNoErrors();

        expect($production->fresh()->status)->toBe(ProductionStatus::Confirmed);
    });

    it('shows bulk confirm action on production list', function () {
        $this->actingAs($this->user);

        Production::factory()->count(2)->planned()->create();

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\ListProductions::class)
            ->assertSee('Confirmer sélection');
    });

    it('duplicates a production from list table action', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->confirmed()->create([
            'batch_number' => 'B5402',
            'slug' => 'b5402',
        ]);

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\ListProductions::class)
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

        $list = Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\ListProductions::class);

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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\CreateProduction::class)
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

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\CreateProduction::class)
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
