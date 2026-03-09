<?php

use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionTaskType;
use App\Models\User;
use App\Services\Production\TaskGenerationService;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the planning board page', function () {
    Livewire::test(\App\Filament\Pages\PlanningBoard::class)
        ->assertSuccessful();
});

it('shows unassigned productions in the sans ligne row', function () {
    $date = Carbon::parse('2026-03-09');

    Production::factory()->confirmed()->create([
        'batch_number' => 'T-UNASSIGNED',
        'production_line_id' => null,
        'production_date' => $date->toDateString(),
    ]);

    Livewire::test(\App\Livewire\Production\PlanningBoard::class)
        ->set('weekStart', $date->toDateString())
        ->call('reload')
        ->assertSee('Sans ligne')
        ->assertSee('T-UNASSIGNED');
});

it('shows inactive lines when they still have visible work in the week', function () {
    $date = Carbon::parse('2026-03-09');
    $line = ProductionLine::factory()->create([
        'name' => 'Soap Line Legacy',
        'is_active' => false,
    ]);
    $taskType = ProductionTaskType::factory()->create();
    $production = Production::factory()->planned()->create([
        'production_line_id' => $line->id,
        'production_date' => $date->toDateString(),
    ]);

    ProductionTask::factory()->create([
        'production_id' => $production->id,
        'production_task_type_id' => $taskType->id,
        'scheduled_date' => $date,
        'date' => $date,
    ]);

    Livewire::test(\App\Livewire\Production\PlanningBoard::class)
        ->set('weekStart', $date->toDateString())
        ->call('reload')
        ->assertSee('Soap Line Legacy (inactive)');
});

it('falls back to production_date when a production has no visible tasks', function () {
    $date = Carbon::parse('2026-03-09');
    $line = ProductionLine::factory()->create(['name' => 'Soap Line 1']);

    Production::factory()->planned()->create([
        'batch_number' => 'T-FALLBACK',
        'production_line_id' => $line->id,
        'production_date' => $date->toDateString(),
    ]);

    Livewire::test(\App\Livewire\Production\PlanningBoard::class)
        ->set('weekStart', $date->toDateString())
        ->call('reload')
        ->assertSee('T-FALLBACK');
});

it('delegates task moves to the task generation service', function () {
    $date = Carbon::parse('2026-03-09');
    $expectedDate = $date->copy()->addDay();
    $task = ProductionTask::factory()->create([
        'scheduled_date' => $date,
        'date' => $date,
    ]);

    $service = Mockery::mock(TaskGenerationService::class);
    $service->shouldReceive('setManualSchedule')
        ->once()
        ->withArgs(function (ProductionTask $movedTask, Carbon $scheduledDate) use ($task, $expectedDate): bool {
            return $movedTask->is($task) && $scheduledDate->toDateString() === $expectedDate->toDateString();
        });

    app()->instance(TaskGenerationService::class, $service);

    Livewire::test(\App\Livewire\Production\PlanningBoard::class)
        ->call('moveTask', $task->id, $expectedDate->toDateString());
});
