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
