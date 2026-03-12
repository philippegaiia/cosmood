<?php

use App\Enums\WaveStatus;
use App\Filament\Resources\Production\ProductionResource\Pages\ListProductions;
use App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves;
use App\Filament\Resources\Supply\SupplyResource\Pages\ListSupplies;
use App\Filament\Widgets\ProductionsSoonReadyWidget;
use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Supply;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('hides production planning actions from operators', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('operator'));
    $this->actingAs($user);

    $production = Production::factory()->planned()->create();

    Livewire::test(ListProductions::class)
        ->assertTableActionHidden('confirmProduction', $production)
        ->assertTableBulkActionHidden('rescheduleSelected')
        ->assertTableBulkActionHidden('confirmSelected');
});

it('hides the finish widget action from operators', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('operator'));
    $this->actingAs($user);

    $production = Production::factory()->inProgress()->create();

    Livewire::test(ProductionsSoonReadyWidget::class)
        ->assertTableActionHidden('finish', $production);
});

it('lets planners replan waves but hides manager-only lifecycle actions', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('planner'));
    $this->actingAs($user);

    $draftWave = ProductionWave::factory()->create(['status' => WaveStatus::Draft]);
    $approvedWave = ProductionWave::factory()->approved()->create();
    Production::factory()->forWave($approvedWave)->create();

    Livewire::test(ListProductionWaves::class)
        ->assertTableActionVisible('replanWave', $approvedWave)
        ->assertTableActionHidden('approve', $draftWave)
        ->assertTableActionHidden('hardDeleteWave', $approvedWave);
});

it('shows manager-only wave lifecycle actions to managers', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('manager'));
    $this->actingAs($user);

    $draftWave = ProductionWave::factory()->create(['status' => WaveStatus::Draft]);
    $approvedWave = ProductionWave::factory()->approved()->create();

    Livewire::test(ListProductionWaves::class)
        ->assertTableActionVisible('approve', $draftWave)
        ->assertTableActionVisible('start', $approvedWave)
        ->assertTableActionVisible('hardDeleteWave', $approvedWave);
});

it('hides inventory-changing supply actions from planners', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('planner'));
    $this->actingAs($user);

    $supply = Supply::factory()->create(['is_in_stock' => true]);

    Livewire::test(ListSupplies::class)
        ->assertTableActionHidden('adjust', $supply)
        ->assertTableActionHidden('markOutOfStock', $supply)
        ->assertTableBulkActionHidden('markOutOfStock');
});

it('shows inventory-changing supply actions to managers', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('manager'));
    $this->actingAs($user);

    $supply = Supply::factory()->create(['is_in_stock' => true]);

    Livewire::test(ListSupplies::class)
        ->assertTableActionVisible('adjust', $supply)
        ->assertTableActionVisible('markOutOfStock', $supply)
        ->assertTableBulkActionVisible('markOutOfStock');
});
