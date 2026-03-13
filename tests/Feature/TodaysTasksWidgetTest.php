<?php

use App\Enums\ProductionStatus;
use App\Filament\Widgets\TodaysTasksWidget;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('lets an operator finish a due task from the dashboard widget', function () {
    $operator = User::factory()->create();
    $operator->assignRole(Role::findOrCreate('operator'));
    $this->actingAs($operator);

    $production = Production::factory()->inProgress()->create([
        'status' => ProductionStatus::Ongoing,
    ]);

    $task = ProductionTask::factory()->create([
        'production_id' => $production->id,
        'scheduled_date' => today(),
        'date' => today(),
        'source' => 'manual',
        'is_finished' => false,
        'cancelled_at' => null,
    ]);

    Livewire::test(TodaysTasksWidget::class)
        ->assertTableActionVisible('finish', $task)
        ->callTableAction('finish', $task);

    expect($task->fresh()->is_finished)->toBeTrue();
});

it('hides the finish action on the dashboard widget when production is not ongoing', function () {
    $operator = User::factory()->create();
    $operator->assignRole(Role::findOrCreate('operator'));
    $this->actingAs($operator);

    $production = Production::factory()->confirmed()->create([
        'status' => ProductionStatus::Confirmed,
    ]);

    $task = ProductionTask::factory()->create([
        'production_id' => $production->id,
        'scheduled_date' => today(),
        'date' => today(),
        'source' => 'manual',
        'is_finished' => false,
        'cancelled_at' => null,
    ]);

    Livewire::test(TodaysTasksWidget::class)
        ->assertTableActionHidden('finish', $task);
});
