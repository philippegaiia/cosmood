<?php

use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Filament\Widgets\ProductionsSoonReadyWidget;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionQcCheck;
use App\Models\Production\ProductionTask;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('does not finish a production from the dashboard when tasks are incomplete', function (): void {
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

    Livewire::test(ProductionsSoonReadyWidget::class)
        ->callAction(TestAction::make('finish')->table($production))
        ->assertNotified(__('Tâches incomplètes'));

    expect($production->fresh()->status)->toBe(ProductionStatus::Ongoing);
});

it('does not finish a production from the dashboard when outputs are missing', function (): void {
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

    Livewire::test(ProductionsSoonReadyWidget::class)
        ->callAction(TestAction::make('finish')->table($production))
        ->assertNotified(__('Sorties à compléter'));

    expect($production->fresh()->status)->toBe(ProductionStatus::Ongoing);
});
