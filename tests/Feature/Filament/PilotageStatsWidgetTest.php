<?php

use App\Filament\Pages\PlanningBoard;
use App\Filament\Pages\ProductionDashboard;
use App\Filament\Pages\PurchasingDashboard;
use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use App\Filament\Widgets\PilotageStatsWidget;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierOrder;
use App\Models\User;
use Livewire\Livewire;

it('renders pilotage stats from compact operational counts', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Production::factory()->planned()->create([
        'production_date' => today()->toDateString(),
    ]);

    Production::factory()->confirmed()->create([
        'production_date' => today()->toDateString(),
    ]);

    Production::factory()->inProgress()->create([
        'production_date' => now()->addDay()->toDateString(),
    ]);

    $readyProduction = Production::factory()->confirmed()->create([
        'production_date' => now()->addDay()->toDateString(),
    ]);

    $readyItem = ProductionItem::factory()->create([
        'production_id' => $readyProduction->id,
    ]);

    ProductionItemAllocation::factory()->create([
        'production_item_id' => $readyItem->id,
    ]);

    ProductionTask::factory()->count(4)->create([
        'scheduled_date' => today()->toDateString(),
        'is_finished' => false,
        'cancelled_at' => null,
    ]);

    Ingredient::factory()->create([
        'stock_min' => 5,
    ]);

    SupplierOrder::factory()->passed()->create();
    SupplierOrder::factory()->confirmed()->create();
    SupplierOrder::factory()->delivered()->create();

    ProductionWave::factory()->approved()->create();
    ProductionWave::factory()->inProgress()->create();

    Livewire::test(PilotageStatsWidget::class)
        ->assertSee('Productions aujourd\'hui')
        ->assertSee('2')
        ->assertSee('À lancer')
        ->assertSee('1')
        ->assertSee('En cours')
        ->assertSee('Tâches du jour')
        ->assertSee('4')
        ->assertSee('Alertes stock')
        ->assertSee('Commandes en attente')
        ->assertSee('3')
        ->assertSee('Vagues actives');
});

it('links each pilotage stat to the relevant drill-down page', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $html = Livewire::test(PilotageStatsWidget::class)->html();

    expect($html)
        ->toContain(PlanningBoard::getUrl())
        ->toContain(ProductionDashboard::getUrl())
        ->toContain(PurchasingDashboard::getUrl())
        ->toContain(ProductionWaveResource::getUrl('index'));
});
