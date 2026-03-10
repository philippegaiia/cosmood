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

it('renders task chips inside their production card order', function () {
    $date = Carbon::parse('2026-03-09');
    $line = ProductionLine::factory()->create(['name' => 'Soap Line 1']);
    $firstTaskType = ProductionTaskType::factory()->create();
    $secondTaskType = ProductionTaskType::factory()->create();

    $firstProduction = Production::factory()->planned()->create([
        'batch_number' => 'T-CARD-001',
        'production_line_id' => $line->id,
        'production_date' => $date->toDateString(),
    ]);
    $secondProduction = Production::factory()->planned()->create([
        'batch_number' => 'T-CARD-002',
        'production_line_id' => $line->id,
        'production_date' => $date->toDateString(),
    ]);

    ProductionTask::factory()->create([
        'production_id' => $firstProduction->id,
        'production_task_type_id' => $firstTaskType->id,
        'name' => 'Fabrication Carte 1',
        'scheduled_date' => $date,
        'date' => $date,
    ]);
    ProductionTask::factory()->create([
        'production_id' => $secondProduction->id,
        'production_task_type_id' => $secondTaskType->id,
        'name' => 'Fabrication Carte 2',
        'scheduled_date' => $date,
        'date' => $date,
    ]);

    Livewire::test(\App\Livewire\Production\PlanningBoard::class)
        ->set('weekStart', $date->toDateString())
        ->call('reload')
        ->assertSeeInOrder([
            'T-CARD-001',
            'Fabrication Carte 1',
            'T-CARD-002',
            'Fabrication Carte 2',
        ]);
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

it('keeps production cards on production_date and task references on scheduled_date', function () {
    $productionDate = Carbon::parse('2026-03-09');
    $taskDate = $productionDate->copy()->addDay();
    $line = ProductionLine::factory()->create(['name' => 'Soap Line 1']);
    $taskType = ProductionTaskType::factory()->create();
    $production = Production::factory()->planned()->create([
        'batch_number' => 'T-SPLIT-001',
        'production_line_id' => $line->id,
        'production_date' => $productionDate->toDateString(),
    ]);

    ProductionTask::factory()->create([
        'production_id' => $production->id,
        'production_task_type_id' => $taskType->id,
        'name' => 'Conditionnement différé',
        'scheduled_date' => $taskDate,
        'date' => $taskDate,
    ]);

    $board = Livewire::test(\App\Livewire\Production\PlanningBoard::class)
        ->set('weekStart', $productionDate->toDateString())
        ->call('reload')
        ->get('board');

    $productionCell = $board['cells'][(string) $line->id][$productionDate->toDateString()];
    $taskCell = $board['cells']['tasks'][$taskDate->toDateString()];

    expect(collect($productionCell['productions'])->pluck('id')->all())->toContain($production->id)
        ->and($productionCell['task_groups'])->toBeEmpty()
        ->and($taskCell['productions'])->toBeEmpty()
        ->and(collect($taskCell['task_groups'])->pluck('id')->all())->toContain($production->id);
});

it('collects task references in the dedicated tasks row', function () {
    $date = Carbon::parse('2026-03-09');
    $line = ProductionLine::factory()->create(['name' => 'Soap Line 1']);
    $taskType = ProductionTaskType::factory()->create();
    $production = Production::factory()->planned()->create([
        'batch_number' => 'T-TASKROW-001',
        'production_line_id' => $line->id,
        'production_date' => $date->toDateString(),
    ]);

    ProductionTask::factory()->create([
        'production_id' => $production->id,
        'production_task_type_id' => $taskType->id,
        'name' => 'Découpe',
        'scheduled_date' => $date,
        'date' => $date,
    ]);

    $board = Livewire::test(\App\Livewire\Production\PlanningBoard::class)
        ->set('weekStart', $date->toDateString())
        ->call('reload')
        ->get('board');

    expect(collect($board['lines'])->pluck('key')->all())->toContain('tasks')
        ->and(collect($board['cells']['tasks'][$date->toDateString()]['task_groups'])->pluck('id')->all())->toContain($production->id);
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

it('handles capacity conflicts without crashing when moving productions without visible tasks', function () {
    $line = ProductionLine::factory()->create([
        'daily_batch_capacity' => 1,
    ]);
    $targetDate = Carbon::parse('2026-03-09');

    Production::factory()->planned()->create([
        'production_line_id' => $line->id,
        'production_date' => $targetDate->toDateString(),
    ]);

    $movable = Production::factory()->planned()->create([
        'production_line_id' => $line->id,
        'production_date' => $targetDate->copy()->addDay()->toDateString(),
    ]);

    Livewire::test(\App\Livewire\Production\PlanningBoard::class)
        ->call('moveProduction', $movable->id, $line->id, $targetDate->toDateString());

    expect($movable->fresh()->production_date->toDateString())
        ->toBe($targetDate->copy()->addDay()->toDateString());
});

it('marks finished and cancelled tasks as non draggable in board payload', function () {
    $date = Carbon::parse('2026-03-09');
    $line = ProductionLine::factory()->create();
    $taskType = ProductionTaskType::factory()->create();
    $production = Production::factory()->planned()->create([
        'production_line_id' => $line->id,
        'production_date' => $date->toDateString(),
    ]);

    $finishedTask = ProductionTask::factory()->create([
        'production_id' => $production->id,
        'production_task_type_id' => $taskType->id,
        'scheduled_date' => $date,
        'date' => $date,
        'is_finished' => true,
    ]);

    $cancelledTask = ProductionTask::factory()->create([
        'production_id' => $production->id,
        'production_task_type_id' => $taskType->id,
        'scheduled_date' => $date,
        'date' => $date,
        'cancelled_at' => now(),
    ]);

    $board = Livewire::test(\App\Livewire\Production\PlanningBoard::class)
        ->set('weekStart', $date->toDateString())
        ->call('reload')
        ->get('board');

    $taskGroup = collect($board['cells']['tasks'][$date->toDateString()]['task_groups'])
        ->firstWhere('id', $production->id);
    $tasks = collect($taskGroup['tasks']);

    expect($tasks->firstWhere('id', $finishedTask->id)['is_draggable'])->toBeFalse()
        ->and($tasks->firstWhere('id', $cancelledTask->id)['is_draggable'])->toBeFalse();
});

it('includes task type colors in board payload', function () {
    $date = Carbon::parse('2026-03-09');
    $line = ProductionLine::factory()->create();
    $taskType = ProductionTaskType::factory()->create([
        'color' => '#16a34a',
        'is_capacity_consuming' => true,
    ]);
    $production = Production::factory()->planned()->create([
        'production_line_id' => $line->id,
        'production_date' => $date->toDateString(),
    ]);

    $task = ProductionTask::factory()->create([
        'production_id' => $production->id,
        'production_task_type_id' => $taskType->id,
        'scheduled_date' => $date,
        'date' => $date,
    ]);

    $board = Livewire::test(\App\Livewire\Production\PlanningBoard::class)
        ->set('weekStart', $date->toDateString())
        ->call('reload')
        ->get('board');

    $taskGroup = collect($board['cells']['tasks'][$date->toDateString()]['task_groups'])
        ->firstWhere('id', $production->id);
    $payload = collect($taskGroup['tasks'])->firstWhere('id', $task->id);

    expect($payload['color'])->toBe('#16a34a')
        ->and($payload['muted_color'])->toContain('rgba(22, 163, 74')
        ->and($payload['text_color'])->toBe('#ffffff');
});

it('attaches tasks to each production payload for the board cards', function () {
    $date = Carbon::parse('2026-03-09');
    $line = ProductionLine::factory()->create();
    $taskType = ProductionTaskType::factory()->create();
    $firstProduction = Production::factory()->planned()->create([
        'batch_number' => 'T-GROUP-001',
        'production_line_id' => $line->id,
        'production_date' => $date->toDateString(),
    ]);
    $secondProduction = Production::factory()->planned()->create([
        'batch_number' => 'T-GROUP-002',
        'production_line_id' => $line->id,
        'production_date' => $date->toDateString(),
    ]);

    $firstTask = ProductionTask::factory()->create([
        'production_id' => $firstProduction->id,
        'production_task_type_id' => $taskType->id,
        'name' => 'Fabrication groupée 1',
        'scheduled_date' => $date,
        'date' => $date,
    ]);
    $secondTask = ProductionTask::factory()->create([
        'production_id' => $secondProduction->id,
        'production_task_type_id' => $taskType->id,
        'name' => 'Fabrication groupée 2',
        'scheduled_date' => $date,
        'date' => $date,
    ]);

    $board = Livewire::test(\App\Livewire\Production\PlanningBoard::class)
        ->set('weekStart', $date->toDateString())
        ->call('reload')
        ->get('board');

    $cards = collect($board['cells'][(string) $line->id][$date->toDateString()]['productions']);
    $firstCard = $cards->firstWhere('id', $firstProduction->id);
    $secondCard = $cards->firstWhere('id', $secondProduction->id);

    expect($firstCard['tasks'])->toHaveCount(1)
        ->and($firstCard['tasks'][0]['id'])->toBe($firstTask->id)
        ->and($secondCard['tasks'])->toHaveCount(1)
        ->and($secondCard['tasks'][0]['id'])->toBe($secondTask->id);
});
