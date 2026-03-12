<?php

use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Filament\Widgets\ReadyToStartProductionsWidget;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('operator'));

    $this->actingAs($user);
});

it('does not move a production to ongoing from the widget when allocations are incomplete', function (): void {
    $production = Production::factory()->confirmed()->create([
        'production_date' => now()->toDateString(),
    ]);

    $production->productionItems()->delete();

    $ingredient = Ingredient::factory()->create();
    $allocatedIngredient = Ingredient::factory()->create();
    $supply = Supply::factory()->inStock(100)->create();

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'required_quantity' => 12,
        'procurement_status' => ProcurementStatus::Ordered,
        'supply_id' => null,
    ]);

    $allocatedItem = ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $allocatedIngredient->id,
        'required_quantity' => 8,
        'procurement_status' => ProcurementStatus::NotOrdered,
        'supply_id' => $supply->id,
    ]);

    ProductionItemAllocation::query()->create([
        'production_item_id' => $allocatedItem->id,
        'supply_id' => $supply->id,
        'quantity' => 8,
        'status' => 'reserved',
        'reserved_at' => now(),
    ]);

    Livewire::test(ReadyToStartProductionsWidget::class)
        ->callAction(TestAction::make('launch')->table($production))
        ->assertNotified(__('Allocations incomplètes'));

    expect($production->fresh()->status)->toBe(ProductionStatus::Confirmed);
});
