<?php

use App\Enums\ProcurementStatus;
use App\Enums\ProductionOutputKind;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionOutput;
use App\Models\Production\ProductionQcCheck;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use Illuminate\Support\Str;

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

    it('blocks planning save when daily line capacity is exceeded', function () {
        $line = ProductionLine::factory()->create([
            'daily_batch_capacity' => 1,
        ]);

        Production::factory()->planned()->onProductionLine($line)->create([
            'production_date' => '2026-03-14',
        ]);

        expect(fn () => Production::factory()->planned()->onProductionLine($line)->create([
            'production_date' => '2026-03-14',
        ]))->toThrow(InvalidArgumentException::class, 'Capacité journalière dépassée');
    });

    it('blocks moving a production to an overloaded line day', function () {
        $line = ProductionLine::factory()->create([
            'daily_batch_capacity' => 1,
        ]);

        Production::factory()->planned()->onProductionLine($line)->create([
            'production_date' => '2026-03-14',
        ]);

        $movableProduction = Production::factory()->planned()->onProductionLine($line)->create([
            'production_date' => '2026-03-15',
        ]);

        expect(fn () => $movableProduction->update([
            'production_date' => '2026-03-14',
        ]))->toThrow(InvalidArgumentException::class, 'Capacité journalière dépassée');
    });

    it('rejects assigning a production to a line outside the product type allowed set', function () {
        $soapLineOne = ProductionLine::factory()->create(['name' => 'Soap Line 1']);
        $soapLineTwo = ProductionLine::factory()->create(['name' => 'Soap Line 2']);
        $deoLine = ProductionLine::factory()->create(['name' => 'Deodorant Line']);

        $productType = ProductType::factory()->create([
            'default_production_line_id' => $soapLineOne->id,
        ]);
        $productType->allowedProductionLines()->sync([$soapLineOne->id, $soapLineTwo->id]);

        expect(fn () => Production::factory()->create([
            'product_type_id' => $productType->id,
            'production_line_id' => $deoLine->id,
        ]))->toThrow(InvalidArgumentException::class, 'n\'est pas autorisée');
    });

    it('keeps backward compatibility when a product type has no allowed line restrictions', function () {
        $line = ProductionLine::factory()->create();
        $productType = ProductType::factory()->create([
            'default_production_line_id' => null,
        ]);
        $productType->allowedProductionLines()->detach();

        $production = Production::factory()->create([
            'product_type_id' => $productType->id,
            'production_line_id' => $line->id,
        ]);

        expect($production->production_line_id)->toBe($line->id);
    });

    it('rejects negative planned quantity', function () {
        $production = Production::factory()->create();

        expect(fn () => $production->update([
            'planned_quantity' => -10,
        ]))->toThrow(InvalidArgumentException::class, 'quantité planifiée ne peut pas être négative');
    });

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

    it('rejects invalid status transitions', function () {
        $production = Production::factory()->inProgress()->create();

        expect(fn () => $production->update(['status' => ProductionStatus::Planned]))
            ->toThrow(InvalidArgumentException::class, 'Invalid production status transition from ongoing to planned.');

        $confirmedProduction = Production::factory()->confirmed()->create();

        expect(fn () => $confirmedProduction->update(['status' => ProductionStatus::Finished]))
            ->toThrow(InvalidArgumentException::class, 'Invalid production status transition from confirmed to finished.');
    });

    it('does not allow cancelled as a normal production transition anymore', function () {
        expect(Production::allowedTransitionsFor(ProductionStatus::Planned))
            ->toEqual([
                ProductionStatus::Planned,
                ProductionStatus::Confirmed,
            ])
            ->and(Production::allowedTransitionsFor(ProductionStatus::Confirmed))
            ->toEqual([
                ProductionStatus::Confirmed,
                ProductionStatus::Ongoing,
            ])
            ->and(Production::allowedTransitionsFor(ProductionStatus::Ongoing))
            ->toEqual([
                ProductionStatus::Ongoing,
                ProductionStatus::Finished,
            ]);
    });

    it('requires lot assignment for all required items before finishing', function () {
        $production = Production::factory()->inProgress()->create();

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'supply_id' => null,
            'supplier_listing_id' => null,
            'supply_batch_number' => null,
        ]);

        expect(fn () => $production->update(['status' => ProductionStatus::Finished]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('requires active tasks to be finished before finishing', function () {
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

        expect(fn () => $production->update(['status' => ProductionStatus::Finished]))
            ->toThrow(InvalidArgumentException::class, 'unfinished tasks');
    });

    it('requires required qc checks to be completed before finishing', function () {
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
            'name' => 'Conditionnement',
            'cancelled_at' => null,
        ]);

        ProductionQcCheck::factory()->create([
            'production_id' => $production->id,
            'required' => true,
        ]);

        expect(fn () => $production->update(['status' => ProductionStatus::Finished]))
            ->toThrow(InvalidArgumentException::class, 'incomplete QC checks');
    });

    it('requires declared outputs before finishing', function () {
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

        expect(fn () => $production->update(['status' => ProductionStatus::Finished]))
            ->toThrow(InvalidArgumentException::class, 'sortie principale');
    });

    it('allows valid status transitions', function () {
        $production = Production::factory()->planned()->create();
        $ingredient = Ingredient::factory()->create();
        $supply = Supply::factory()->inStock(100)->create();

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 10,
            'procurement_status' => ProcurementStatus::NotOrdered,
            'supply_id' => $supply->id,
        ]);

        ProductionItemAllocation::query()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 10,
            'status' => 'reserved',
            'reserved_at' => now(),
        ]);

        $production->update(['status' => ProductionStatus::Confirmed]);
        $production->update(['status' => ProductionStatus::Ongoing]);

        ProductionOutput::factory()->create([
            'production_id' => $production->id,
            'kind' => ProductionOutputKind::MainProduct,
            'quantity' => $production->expected_units,
            'unit' => 'u',
        ]);

        $production->update(['status' => ProductionStatus::Finished]);

        expect($production->fresh()->status)->toBe(ProductionStatus::Finished);
    });

    it('blocks transition to ongoing when at least one production item is not allocated', function () {
        $production = Production::factory()->confirmed()->create();
        $ingredient = Ingredient::factory()->create();

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 12,
            'procurement_status' => ProcurementStatus::Ordered,
            'supply_id' => null,
        ]);

        expect(fn () => $production->update(['status' => ProductionStatus::Ongoing]))
            ->toThrow(InvalidArgumentException::class, 'Cannot set production to ongoing: unallocated items');
    });

    it('prevents deleting finished productions', function () {
        $production = Production::factory()->finished()->create();

        expect(fn () => $production->delete())
            ->toThrow(InvalidArgumentException::class, 'Finished productions cannot be deleted.');

        expect(Production::query()->find($production->id))->not->toBeNull();
    });

    it('auto-calculates ready date from production date based on product type', function () {
        $soapType = ProductType::factory()->create([
            'slug' => 'soap-bars',
            'name' => 'Soap Bars',
        ]);
        $balmType = ProductType::factory()->create([
            'slug' => 'balms',
            'name' => 'Balms',
        ]);

        $soapProduction = Production::factory()->create([
            'product_type_id' => $soapType->id,
            'production_date' => '2026-03-01',
            'ready_date' => null,
        ]);

        $balmProduction = Production::factory()->create([
            'product_type_id' => $balmType->id,
            'production_date' => '2026-03-01',
            'ready_date' => null,
        ]);

        expect($soapProduction->fresh()->ready_date?->format('Y-m-d'))->toBe('2026-04-05')
            ->and($balmProduction->fresh()->ready_date?->format('Y-m-d'))->toBe('2026-03-03');
    });

    it('keeps manually provided ready date', function () {
        $soapType = ProductType::factory()->create([
            'slug' => 'soap-bars',
            'name' => 'Soap Bars',
        ]);

        $production = Production::factory()->create([
            'product_type_id' => $soapType->id,
            'production_date' => '2026-03-01',
            'ready_date' => '2026-03-15',
        ]);

        expect($production->fresh()->ready_date?->format('Y-m-d'))->toBe('2026-03-15');
    });

    it('can have product type defaults applied', function () {
        $productType = ProductType::factory()->soap()->create();
        $production = Production::factory()->withProductType($productType)->create();

        expect($production->product_type_id)->toBe($productType->id)
            ->and($production->sizing_mode)->toBe(SizingMode::OilWeight)
            ->and((float) $production->planned_quantity)->toBe(26.0)
            ->and($production->expected_units)->toBe(288);
    });

    it('formats lot label with permanent number when available', function () {
        $production = Production::factory()->create([
            'batch_number' => 'B-PLAN-001',
            'permanent_batch_number' => '000321',
        ]);

        expect($production->getLotIdentifier())->toBe('000321')
            ->and($production->getLotDisplayLabel())->toBe('000321 (plan B-PLAN-001)');
    });

    it('maps calendar event metadata with status color and lot label', function () {
        $production = Production::factory()->cancelled()->create([
            'batch_number' => 'B-PLAN-001',
            'permanent_batch_number' => '000321',
        ]);

        $production->load('product');
        $event = $production->toCalendarEvent();

        expect($event->getBackgroundColor())->toBe('#ef4444')
            ->and($event->getStart()->toDateString())->toBe($production->production_date->toDateString())
            ->and($event->getEnd()->toDateString())->toBe($production->production_date->toDateString())
            ->and($event->getAllDay())->toBeTrue()
            ->and($event->getExtendedProps()['lotLabel'])->toBe('000321 (B-PLAN-001)')
            ->and($event->getExtendedProps()['status'])->toBe(ProductionStatus::Cancelled->value)
            ->and($event->getExtendedProps()['url'])->toContain('/productions/'.$production->id)
            ->and($event->getResourceIds())->toContain('line-unassigned');
    });

    it('maps production line to calendar resource id', function () {
        $line = ProductionLine::factory()->create();
        $production = Production::factory()->onProductionLine($line)->create();

        $event = $production->toCalendarEvent();

        expect($event->getResourceIds())->toContain('line-'.$line->id);
    });

    it('computes supply coverage traffic light states', function () {
        $production = Production::factory()->create();

        ProductionItem::factory()->count(2)->create([
            'production_id' => $production->id,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        expect($production->fresh()->getSupplyCoverageState())->toBe('missing');

        $production->productionItems()->update([
            'procurement_status' => ProcurementStatus::Ordered,
        ]);

        expect($production->fresh()->getSupplyCoverageState())->toBe('ordered');

        $production->productionItems()->update([
            'procurement_status' => ProcurementStatus::Received,
        ]);

        expect($production->fresh()->getSupplyCoverageState())->toBe('received');
    });

    it('marks production as covered when all items are fully allocated', function () {
        $product = Product::factory()->create();
        $formula = Formula::query()->create([
            'name' => 'Formula fully allocated coverage',
            'slug' => Str::slug('formula-coverage-'.Str::uuid()),
            'code' => 'FRM-'.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $production = Production::query()->create([
            'product_id' => $product->id,
            'formula_id' => $formula->id,
            'batch_number' => 'T95001',
            'slug' => 'batch-coverage-allocated',
            'status' => ProductionStatus::Planned,
            'sizing_mode' => SizingMode::OilWeight,
            'planned_quantity' => 12,
            'expected_units' => 100,
            'production_date' => now()->toDateString(),
            'ready_date' => now()->addDays(2)->toDateString(),
            'organic' => true,
        ]);

        $supply = Supply::factory()->inStock(100)->create();

        $item = ProductionItem::factory()->create([
            'production_id' => $production->id,
            'required_quantity' => 12,
            'procurement_status' => ProcurementStatus::NotOrdered,
            'supply_id' => $supply->id,
        ]);

        ProductionItemAllocation::query()->create([
            'production_item_id' => $item->id,
            'supply_id' => $supply->id,
            'quantity' => 12,
            'status' => 'reserved',
            'reserved_at' => now(),
        ]);

        expect($production->fresh()->getSupplyCoverageState())->toBe('received');
    });
});

describe('Production Relationships', function () {
    it('belongs to a product', function () {
        $production = Production::factory()->create();

        expect($production->product)->not->toBeNull();
    });

    it('belongs to a formula', function () {
        $production = Production::factory()->create();

        expect($production->formula)->not->toBeNull();
    });

    it('can have production items', function () {
        $production = Production::factory()->create();

        expect($production->productionItems())->not->toBeNull();
    });

    it('can have production tasks', function () {
        $production = Production::factory()->create();

        expect($production->productionTasks())->not->toBeNull();
    });

    it('auto-links the main output to the production product', function () {
        $production = Production::factory()->create();

        $output = ProductionOutput::factory()->create([
            'production_id' => $production->id,
            'kind' => ProductionOutputKind::MainProduct,
            'product_id' => null,
            'quantity' => $production->expected_units,
            'unit' => 'u',
        ]);

        expect($output->fresh()->product_id)->toBe($production->product_id);
    });

    it('prioritizes the rework output for stock creation on sellable productions', function () {
        $production = Production::factory()->create();
        $reworkIngredient = Ingredient::factory()->manufactured()->create();

        ProductionOutput::factory()->create([
            'production_id' => $production->id,
            'kind' => ProductionOutputKind::MainProduct,
            'quantity' => $production->expected_units,
            'unit' => 'u',
        ]);

        $reworkOutput = ProductionOutput::factory()->create([
            'production_id' => $production->id,
            'kind' => ProductionOutputKind::ReworkMaterial,
            'ingredient_id' => $reworkIngredient->id,
            'quantity' => 2.5,
            'unit' => 'kg',
        ]);

        expect($production->fresh()->getStockCreatingOutput()?->id)->toBe($reworkOutput->id);
    });

    it('can reference a manufactured ingredient output', function () {
        $ingredient = Ingredient::factory()->manufactured()->create();

        $production = Production::factory()->masterbatch()->create([
            'produced_ingredient_id' => $ingredient->id,
        ]);

        expect($production->producedIngredient)->not->toBeNull()
            ->and($production->producedIngredient->id)->toBe($ingredient->id);
    });

    it('can link to produced supply lot', function () {
        $production = Production::factory()->create();
        $supply = Supply::factory()->create([
            'source_production_id' => $production->id,
        ]);

        expect($production->producedSupply)->not->toBeNull()
            ->and($production->producedSupply->id)->toBe($supply->id);
    });
});

describe('Masterbatch Productions', function () {
    it('can be used by other productions', function () {
        $masterbatch = Production::factory()->masterbatch()->finished()->create();
        $soap1 = Production::factory()->usingMasterbatch($masterbatch)->create();
        $soap2 = Production::factory()->usingMasterbatch($masterbatch)->create();

        expect($masterbatch->usedInProductions)->toHaveCount(2);
    });
});
