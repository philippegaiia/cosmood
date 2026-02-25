<?php

use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Models\Production\BatchSizePreset;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
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
            ->toContain(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionTasksRelationManager::class)
            ->toContain(\App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionQcChecksRelationManager::class);
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
        ])->assertSet('data.productionItems', function (array $items): bool {
            return collect($items)
                ->contains(fn (array $item): bool => ($item['supply_batch_number'] ?? null) === 'LOT-UI-001');
        });
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
            'formula_id' => $originalProduct->formulas()->value('id'),
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
            ->assertSet('data.permanent_batch_number', '00001');

        expect($production->fresh()->permanent_batch_number)->toBe('00001');
    });

    it('asks confirmation on save when soap production total saponified is not 100 percent', function () {
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
                'notes' => 'Save should require confirmation',
            ])
            ->call('save')
            ->assertNotified('Total saponifie different de 100%');

        expect($production->fresh()->notes)->not->toBe('Save should require confirmation');

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class, [
            'record' => $production->id,
        ])
            ->fillForm([
                'notes' => 'Save should require confirmation',
            ])
            ->call('save')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($production->fresh()->notes)->toBe('Save should require confirmation');
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
            ->assertNotNotified('Total saponifie different de 100%')
            ->assertHasNoFormErrors();

        expect($production->fresh()->notes)->toBe('Save without confirmation');
    });

    it('asks confirmation on save when linked formula has manual soap control enabled', function () {
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
                'notes' => 'Formula flag should require confirmation',
            ])
            ->call('save')
            ->assertNotified('Total saponifie different de 100%');

        expect($production->fresh()->notes)->not->toBe('Formula flag should require confirmation');

        Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\EditProduction::class, [
            'record' => $production->id,
        ])
            ->fillForm([
                'notes' => 'Formula flag should require confirmation',
            ])
            ->call('save')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($production->fresh()->notes)->toBe('Formula flag should require confirmation');
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

        ProductionItem::factory()->create([
            'production_id' => $masterbatch->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'supply_id' => $supply->id,
            'supply_batch_number' => 'LOT-MB-TRACE',
            'phase' => '10',
            'percentage_of_oils' => 60,
            'is_supplied' => true,
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
            ->and($duplicate->batch_number)->toContain('B5402-D')
            ->and($duplicate->batch_number)->not->toBe($production->batch_number);
    });

    it('duplicates increment with D01 D02 pattern', function () {
        $this->actingAs($this->user);

        $production = Production::factory()->confirmed()->create([
            'batch_number' => 'B7000',
            'slug' => 'b7000',
        ]);

        $list = Livewire::test(\App\Filament\Resources\Production\ProductionResource\Pages\ListProductions::class);

        $list->callAction(TestAction::make('duplicate')->table($production))->assertHasNoErrors();

        $firstDuplicate = Production::query()->where('batch_number', 'B7000-D01')->first();
        expect($firstDuplicate)->not->toBeNull();

        $list->callAction(TestAction::make('duplicate')->table($firstDuplicate))->assertHasNoErrors();

        $secondDuplicate = Production::query()->where('batch_number', 'B7000-D02')->first();

        expect($secondDuplicate)->not->toBeNull();
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
