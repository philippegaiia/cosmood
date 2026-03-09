<?php

use App\Models\Production\ProductionTaskType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists production task types in table', function () {
    $types = ProductionTaskType::factory()->count(3)->create();

    Livewire::test(\App\Filament\Resources\Production\ProductionTaskTypeResource\Pages\ListProductionTaskTypes::class)
        ->assertCanSeeTableRecords($types);
});

it('searches production task types by name', function () {
    $typeA = ProductionTaskType::factory()->create(['name' => 'Pesée']);
    $typeB = ProductionTaskType::factory()->create(['name' => 'Découpe']);

    Livewire::test(\App\Filament\Resources\Production\ProductionTaskTypeResource\Pages\ListProductionTaskTypes::class)
        ->searchTable('Pesée')
        ->assertCanSeeTableRecords([$typeA])
        ->assertCanNotSeeTableRecords([$typeB]);
});

it('persists capacity toggle on production task types', function () {
    Livewire::test(\App\Filament\Resources\Production\ProductionTaskTypeResource\Pages\CreateProductionTaskType::class)
        ->fillForm([
            'name' => 'Curing',
            'slug' => 'curing',
            'duration' => 60,
            'is_active' => true,
            'is_capacity_consuming' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ProductionTaskType::query()->where('slug', 'curing')->firstOrFail()->is_capacity_consuming)->toBeFalse();
});

it('defaults task types to capacity consuming', function () {
    $taskType = ProductionTaskType::factory()->create();

    expect($taskType->is_capacity_consuming)->toBeTrue();
});
