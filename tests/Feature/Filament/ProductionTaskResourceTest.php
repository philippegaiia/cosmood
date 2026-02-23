<?php

use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionTaskType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists production tasks in table', function () {
    $tasks = ProductionTask::factory()->count(3)->create();

    Livewire::test(\App\Filament\Resources\Production\ProductionTaskResource\Pages\ListProductionTasks::class)
        ->assertCanSeeTableRecords($tasks);
});

it('searches production tasks by batch number', function () {
    $taskType = ProductionTaskType::factory()->create();
    $productionA = Production::factory()->create([
        'batch_number' => 'BATCH-ALPHA',
    ]);
    $productionB = Production::factory()->create([
        'batch_number' => 'BATCH-BETA',
    ]);

    $taskA = ProductionTask::factory()->create([
        'production_id' => $productionA->id,
        'production_task_type_id' => $taskType->id,
    ]);

    $taskB = ProductionTask::factory()->create([
        'production_id' => $productionB->id,
        'production_task_type_id' => $taskType->id,
    ]);

    Livewire::test(\App\Filament\Resources\Production\ProductionTaskResource\Pages\ListProductionTasks::class)
        ->searchTable('ALPHA')
        ->assertCanSeeTableRecords([$taskA])
        ->assertCanNotSeeTableRecords([$taskB]);
});
