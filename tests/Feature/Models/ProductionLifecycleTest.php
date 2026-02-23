<?php

use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Enums\WaveStatus;
use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Product;
use App\Models\Production\ProductCategory;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use App\Models\Production\QcTemplate;
use App\Models\Production\QcTemplateItem;
use App\Models\Production\TaskTemplate;
use App\Models\Production\TaskTemplateItem;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Production lifecycle orchestration', function () {
    it('generates template tasks when production is confirmed', function () {
        $category = ProductCategory::factory()->create();
        $productType = ProductType::factory()->create(['product_category_id' => $category->id]);
        $product = Product::factory()->create([
            'product_category_id' => $category->id,
            'product_type_id' => $productType->id,
        ]);
        $production = Production::factory()->create([
            'product_id' => $product->id,
            'product_type_id' => $productType->id,
            'status' => ProductionStatus::Planned,
            'production_date' => now()->toDateString(),
        ]);

        $template = TaskTemplate::factory()->default()->create([
            'product_type_id' => $productType->id,
            'product_category_id' => $category->id,
        ]);

        TaskTemplateItem::factory()->forTemplate($template)->create([
            'name' => 'Préparation',
            'offset_days' => 0,
            'skip_weekends' => true,
        ]);

        $production->update(['status' => ProductionStatus::Confirmed]);

        expect($production->fresh()->productionTasks)->toHaveCount(1)
            ->and($production->fresh()->productionTasks->first()->source)->toBe('template');
    });

    it('generates template tasks when created directly as confirmed', function () {
        $category = ProductCategory::factory()->create();
        $productType = ProductType::factory()->create(['product_category_id' => $category->id]);
        $product = Product::factory()->create([
            'product_category_id' => $category->id,
            'product_type_id' => $productType->id,
        ]);

        $template = TaskTemplate::factory()->default()->create([
            'product_type_id' => $productType->id,
            'product_category_id' => $category->id,
        ]);

        TaskTemplateItem::factory()->forTemplate($template)->create([
            'name' => 'Production',
            'offset_days' => 0,
            'skip_weekends' => true,
            'sort_order' => 1,
        ]);

        $production = Production::factory()->create([
            'product_id' => $product->id,
            'product_type_id' => $productType->id,
            'status' => ProductionStatus::Confirmed,
            'production_date' => now()->toDateString(),
        ]);

        expect($production->fresh()->productionTasks)->toHaveCount(1);
    });

    it('creates production items from formula when production is created', function () {
        $ingredientA = Ingredient::factory()->create();
        $ingredientB = Ingredient::factory()->create();

        SupplierListing::factory()->create(['ingredient_id' => $ingredientA->id]);
        SupplierListing::factory()->create(['ingredient_id' => $ingredientB->id]);

        $formula = Formula::factory()->create();

        FormulaItem::factory()->create([
            'formula_id' => $formula->id,
            'ingredient_id' => $ingredientA->id,
            'percentage_of_oils' => 60,
            'phase' => Phases::Saponification,
            'organic' => true,
            'sort' => 1,
        ]);

        FormulaItem::factory()->create([
            'formula_id' => $formula->id,
            'ingredient_id' => $ingredientB->id,
            'percentage_of_oils' => 40,
            'phase' => Phases::Additives,
            'organic' => false,
            'sort' => 2,
        ]);

        $production = Production::factory()->create([
            'formula_id' => $formula->id,
        ]);

        $production->refresh();

        expect($production->productionItems)->toHaveCount(2)
            ->and((float) $production->productionItems->first()->percentage_of_oils)->toBe(60.0)
            ->and($production->productionItems->first()->phase)->toBe(Phases::Saponification->value);
    });

    it('generates qc checks from product type template when production is created', function () {
        $productType = ProductType::factory()->create();

        $qcTemplate = QcTemplate::factory()->create([
            'product_type_id' => $productType->id,
            'is_default' => true,
            'is_active' => true,
        ]);

        QcTemplateItem::factory()->count(2)->create([
            'qc_template_id' => $qcTemplate->id,
        ]);

        $production = Production::factory()->create([
            'product_type_id' => $productType->id,
        ]);

        expect($production->fresh()->productionQcChecks)->toHaveCount(2);
    });

    it('creates production items even when no supplier listing exists yet', function () {
        $ingredient = Ingredient::factory()->create();
        $formula = Formula::factory()->create();

        FormulaItem::factory()->create([
            'formula_id' => $formula->id,
            'ingredient_id' => $ingredient->id,
            'percentage_of_oils' => 100,
            'phase' => Phases::Saponification,
            'organic' => true,
            'sort' => 1,
        ]);

        $production = Production::factory()->create([
            'formula_id' => $formula->id,
        ]);

        $item = $production->fresh()->productionItems->first();

        expect($item)->not->toBeNull()
            ->and($item->supplier_listing_id)->toBeNull()
            ->and($item->supply_id)->toBeNull();
    });

    it('deletes tasks when production is cancelled', function () {
        $production = Production::factory()->create(['status' => ProductionStatus::Confirmed]);

        ProductionTask::factory()->create([
            'production_id' => $production->id,
            'is_finished' => false,
            'cancelled_at' => null,
        ]);

        ProductionTask::factory()->create([
            'production_id' => $production->id,
            'is_finished' => true,
            'cancelled_at' => null,
        ]);

        $production->update(['status' => ProductionStatus::Cancelled]);

        expect($production->fresh()->productionTasks)->toHaveCount(0);
    });

    it('deletes tasks when production is moved back to planned', function () {
        $production = Production::factory()->create(['status' => ProductionStatus::Confirmed]);

        ProductionTask::factory()->count(2)->create([
            'production_id' => $production->id,
        ]);

        $production->update(['status' => ProductionStatus::Planned]);

        expect($production->fresh()->productionTasks)->toHaveCount(0);
    });

    it('deletes tasks when production is moved to simulation', function () {
        $production = Production::factory()->create(['status' => ProductionStatus::Confirmed]);

        ProductionTask::factory()->count(2)->create([
            'production_id' => $production->id,
        ]);

        $production->update(['status' => ProductionStatus::Simulated]);

        expect($production->fresh()->productionTasks)->toHaveCount(0);
    });

    it('keeps planned productions taskless when production date changes', function () {
        $production = Production::factory()->create(['status' => ProductionStatus::Planned]);

        ProductionTask::factory()->count(2)->create([
            'production_id' => $production->id,
        ]);

        $production->update([
            'production_date' => now()->addDays(5)->toDateString(),
        ]);

        expect($production->fresh()->productionTasks)->toHaveCount(0);
    });

    it('keeps tasks when production is ongoing or finished', function () {
        $production = Production::factory()->create(['status' => ProductionStatus::Confirmed]);

        ProductionTask::factory()->count(2)->create([
            'production_id' => $production->id,
        ]);

        $production->update(['status' => ProductionStatus::Ongoing]);
        expect($production->fresh()->productionTasks)->toHaveCount(2);

        $production->update(['status' => ProductionStatus::Finished]);
        expect($production->fresh()->productionTasks)->toHaveCount(2);
    });

    it('auto-reschedules non-manual tasks when production date changes', function () {
        $category = ProductCategory::factory()->create();
        $productType = ProductType::factory()->create(['product_category_id' => $category->id]);
        $product = Product::factory()->create([
            'product_category_id' => $category->id,
            'product_type_id' => $productType->id,
        ]);

        $template = TaskTemplate::factory()->default()->create([
            'product_type_id' => $productType->id,
            'product_category_id' => $category->id,
        ]);

        TaskTemplateItem::factory()->forTemplate($template)->create([
            'name' => 'Production',
            'offset_days' => 0,
            'sort_order' => 1,
        ]);

        TaskTemplateItem::factory()->forTemplate($template)->create([
            'name' => 'Packing',
            'offset_days' => 2,
            'sort_order' => 2,
        ]);

        $production = Production::factory()->create([
            'product_id' => $product->id,
            'product_type_id' => $productType->id,
            'status' => ProductionStatus::Planned,
            'production_date' => '2026-03-02',
        ]);

        $production->update(['status' => ProductionStatus::Confirmed]);

        $production->refresh();
        $task = $production->productionTasks()->where('sequence_order', 2)->first();
        $task->update([
            'scheduled_date' => '2026-03-20',
            'date' => '2026-03-20',
            'is_manual_schedule' => true,
        ]);

        $production->update(['production_date' => '2026-03-10']);

        $production->refresh();
        $autoTask = $production->productionTasks()->where('sequence_order', 1)->first();
        $manualTask = $production->productionTasks()->where('sequence_order', 2)->first();

        expect($autoTask->scheduled_date->format('Y-m-d'))->toBe('2026-03-10')
            ->and($manualTask->scheduled_date->format('Y-m-d'))->toBe('2026-03-20')
            ->and($manualTask->is_manual_schedule)->toBeTrue();
    });
});

describe('ProductionWave transitions', function () {
    it('can transition draft -> approved -> in_progress -> completed', function () {
        $user = User::factory()->create();
        $wave = ProductionWave::factory()->draft()->create([
            'status' => WaveStatus::Draft,
        ]);

        $wave->approve($user, now()->addDay(), now()->addDays(5));

        expect($wave->fresh()->status)->toBe(WaveStatus::Approved)
            ->and($wave->fresh()->approved_by)->toBe($user->id);

        $wave->fresh()->start();
        expect($wave->fresh()->status)->toBe(WaveStatus::InProgress)
            ->and($wave->fresh()->started_at)->not->toBeNull();

        $wave->fresh()->complete();
        expect($wave->fresh()->status)->toBe(WaveStatus::Completed)
            ->and($wave->fresh()->completed_at)->not->toBeNull();
    });

    it('rejects invalid transitions', function () {
        $user = User::factory()->create();
        $completedWave = ProductionWave::factory()->completed()->create();

        expect(fn () => $completedWave->cancel())
            ->toThrow(InvalidArgumentException::class, 'Completed waves cannot be cancelled');

        $inProgressWave = ProductionWave::factory()->inProgress()->create();

        expect(fn () => $inProgressWave->approve($user))
            ->toThrow(InvalidArgumentException::class, 'Only draft waves can be approved');
    });
});
