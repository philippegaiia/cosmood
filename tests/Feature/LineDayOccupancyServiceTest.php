<?php

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionTaskType;
use App\Services\Production\LineDayOccupancyService;
use Carbon\Carbon;

describe('LineDayOccupancyService', function () {
    it('counts distinct productions instead of tasks', function () {
        $line = ProductionLine::factory()->create(['daily_batch_capacity' => 4]);
        $taskType = ProductionTaskType::factory()->create(['is_capacity_consuming' => true]);
        $date = Carbon::parse('2026-03-09');

        $productionOne = Production::factory()->planned()->create([
            'production_line_id' => $line->id,
            'production_date' => $date->toDateString(),
        ]);
        $productionTwo = Production::factory()->confirmed()->create([
            'production_line_id' => $line->id,
            'production_date' => $date->toDateString(),
        ]);

        ProductionTask::factory()->create([
            'production_id' => $productionOne->id,
            'production_task_type_id' => $taskType->id,
            'scheduled_date' => $date,
            'date' => $date,
        ]);
        ProductionTask::factory()->create([
            'production_id' => $productionOne->id,
            'production_task_type_id' => $taskType->id,
            'scheduled_date' => $date,
            'date' => $date,
        ]);
        ProductionTask::factory()->create([
            'production_id' => $productionTwo->id,
            'production_task_type_id' => $taskType->id,
            'scheduled_date' => $date,
            'date' => $date,
        ]);

        $occupancy = app(LineDayOccupancyService::class)->getOccupancy([$line->id], $date->copy(), $date->copy());

        expect($occupancy[$line->id][$date->toDateString()]['used'])->toBe(2)
            ->and($occupancy[$line->id][$date->toDateString()]['capacity'])->toBe(4);
    });

    it('excludes passive tasks from occupancy', function () {
        $line = ProductionLine::factory()->create(['daily_batch_capacity' => 3]);
        $taskType = ProductionTaskType::factory()->passive()->create();
        $date = Carbon::parse('2026-03-09');
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

        $occupancy = app(LineDayOccupancyService::class)->getOccupancy([$line->id], $date->copy(), $date->copy());

        expect($occupancy[$line->id][$date->toDateString()]['used'])->toBe(0);
    });

    it('excludes soft deleted productions and tasks from occupancy', function () {
        $line = ProductionLine::factory()->create(['daily_batch_capacity' => 2]);
        $taskType = ProductionTaskType::factory()->create(['is_capacity_consuming' => true]);
        $date = Carbon::parse('2026-03-09');

        $activeProduction = Production::factory()->create([
            'status' => ProductionStatus::Planned,
            'production_line_id' => $line->id,
            'production_date' => $date->toDateString(),
        ]);
        $deletedProduction = Production::factory()->create([
            'status' => ProductionStatus::Confirmed,
            'production_line_id' => $line->id,
            'production_date' => $date->toDateString(),
        ]);

        ProductionTask::factory()->create([
            'production_id' => $activeProduction->id,
            'production_task_type_id' => $taskType->id,
            'scheduled_date' => $date,
            'date' => $date,
        ]);
        $deletedTask = ProductionTask::factory()->create([
            'production_id' => $activeProduction->id,
            'production_task_type_id' => $taskType->id,
            'scheduled_date' => $date,
            'date' => $date,
        ]);
        ProductionTask::factory()->create([
            'production_id' => $deletedProduction->id,
            'production_task_type_id' => $taskType->id,
            'scheduled_date' => $date,
            'date' => $date,
        ]);

        $deletedTask->delete();
        $deletedProduction->delete();

        $occupancy = app(LineDayOccupancyService::class)->getOccupancy([$line->id], $date->copy(), $date->copy());

        expect($occupancy[$line->id][$date->toDateString()]['used'])->toBe(1);
    });
});
