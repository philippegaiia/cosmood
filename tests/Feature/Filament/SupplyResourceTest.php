<?php

use App\Enums\ProductionStatus;
use App\Enums\WaveStatus;
use App\Filament\Resources\Supply\SupplyResource;
use App\Filament\Resources\Supply\SupplyResource\Pages\EditSupply;
use App\Filament\Resources\Supply\SupplyResource\Pages\ListSupplies;
use App\Filament\Resources\Supply\SupplyResource\Pages\ViewSupply;
use App\Filament\Resources\Supply\SupplyResource\RelationManagers\StockMovementsRelationManager;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SuppliesMovement;
use App\Models\Supply\Supply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists supplies in table', function () {
    $supplies = Supply::factory()->count(3)->create();

    Livewire::test(ListSupplies::class)
        ->assertCanSeeTableRecords($supplies);
});

it('loads supplies list page successfully', function () {
    $supplyA = Supply::factory()->create(['batch_number' => 'BATCH-ALPHA-01']);
    $supplyB = Supply::factory()->create(['batch_number' => 'BATCH-BETA-01']);

    Livewire::test(ListSupplies::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$supplyA, $supplyB]);
});

it('shows allocated stock from movements in the supplies table', function () {
    $supply = Supply::factory()->create([
        'initial_quantity' => 50,
        'quantity_in' => null,
        'quantity_out' => 0,
        'allocated_quantity' => 0,
    ]);

    SuppliesMovement::query()->create([
        'supply_id' => $supply->id,
        'quantity' => 15,
        'movement_type' => 'allocation',
        'moved_at' => now(),
        'reason' => 'Planned production allocation',
        'user_id' => auth()->id(),
    ]);

    Livewire::test(ListSupplies::class)
        ->assertSee('35.00')
        ->assertSee('Alloué: 15.00');
});

it('disables manual supply creation from inventory resource', function () {
    expect(SupplyResource::canCreate())->toBeFalse();
});

it('does not persist direct edits on stock quantity fields', function () {
    $supply = Supply::factory()->create([
        'initial_quantity' => 5,
        'quantity_in' => 12,
        'quantity_out' => 3,
    ]);

    $manager = User::factory()->create();
    $manager->assignRole(Role::findOrCreate('manager'));
    $this->actingAs($manager);

    Livewire::test(EditSupply::class, ['record' => $supply->id])
        ->fillForm([
            'initial_quantity' => 999,
            'quantity_in' => 888,
            'quantity_out' => 777,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $supply->fresh()->initial_quantity)->toBe(5.0)
        ->and((float) $supply->fresh()->quantity_in)->toBe(12.0)
        ->and((float) $supply->fresh()->quantity_out)->toBe(3.0);
});

it('limits supply edit access to managers', function () {
    $supply = Supply::factory()->create();

    $planner = User::factory()->create();
    $planner->assignRole(Role::findOrCreate('planner'));
    $this->actingAs($planner);

    expect(SupplyResource::canEdit($supply))->toBeFalse();

    $manager = User::factory()->create();
    $manager->assignRole(Role::findOrCreate('manager'));
    $this->actingAs($manager);

    expect(SupplyResource::canEdit($supply))->toBeTrue();
});

it('limits stock movement relation visibility to managers', function () {
    $supply = Supply::factory()->create();

    $planner = User::factory()->create();
    $planner->assignRole(Role::findOrCreate('planner'));
    $this->actingAs($planner);

    expect(StockMovementsRelationManager::canViewForRecord(
        $supply,
        ViewSupply::class,
    ))->toBeFalse();

    $manager = User::factory()->create();
    $manager->assignRole(Role::findOrCreate('manager'));
    $this->actingAs($manager);

    expect(StockMovementsRelationManager::canViewForRecord(
        $supply,
        ViewSupply::class,
    ))->toBeTrue();
});

it('shows quick allocation actions on received lots for planners', function () {
    $planner = User::factory()->create();
    $planner->assignRole(Role::findOrCreate('planner'));
    $this->actingAs($planner);

    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()->create([
        'ingredient_id' => $ingredient->id,
    ]);

    $wave = ProductionWave::factory()->create([
        'status' => WaveStatus::Approved,
        'planned_start_date' => '2026-03-20',
    ]);

    $production = Production::factory()->create([
        'production_wave_id' => $wave->id,
        'status' => ProductionStatus::Planned,
        'production_date' => '2026-03-20',
    ]);

    ProductionItem::factory()->create([
        'production_id' => $production->id,
        'ingredient_id' => $ingredient->id,
        'supplier_listing_id' => $listing->id,
        'required_quantity' => 20.0,
    ]);

    $supply = Supply::factory()->inStock(25.0)->create([
        'supplier_listing_id' => $listing->id,
    ]);

    Livewire::test(ListSupplies::class)
        ->assertTableActionVisible('allocateToWave', $supply)
        ->assertTableActionVisible('allocateToProduction', $supply);
});
