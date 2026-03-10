<?php

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use App\Services\Production\LineDayOccupancyService;
use Carbon\Carbon;

describe('LineDayOccupancyService', function () {
    it('counts productions on their manufacturing date', function () {
        $line = ProductionLine::factory()->create(['daily_batch_capacity' => 4]);
        $date = Carbon::parse('2026-03-09');

        Production::factory()->planned()->create([
            'production_line_id' => $line->id,
            'production_date' => $date->toDateString(),
        ]);
        Production::factory()->confirmed()->create([
            'production_line_id' => $line->id,
            'production_date' => $date->toDateString(),
        ]);

        $occupancy = app(LineDayOccupancyService::class)->getOccupancy([$line->id], $date->copy(), $date->copy());

        expect($occupancy[$line->id][$date->toDateString()]['used'])->toBe(2)
            ->and($occupancy[$line->id][$date->toDateString()]['capacity'])->toBe(4);
    });

    it('ignores later task dates when computing production capacity', function () {
        $line = ProductionLine::factory()->create(['daily_batch_capacity' => 3]);
        $productionDate = Carbon::parse('2026-03-09');
        $taskDate = $productionDate->copy()->addDay();
        $production = Production::factory()->planned()->create([
            'production_line_id' => $line->id,
            'production_date' => $productionDate->toDateString(),
        ]);

        \App\Models\Production\ProductionTask::factory()->create([
            'production_id' => $production->id,
            'scheduled_date' => $taskDate,
            'date' => $taskDate,
        ]);

        $occupancy = app(LineDayOccupancyService::class)->getOccupancy([$line->id], $productionDate->copy(), $taskDate->copy());

        expect($occupancy[$line->id][$productionDate->toDateString()]['used'])->toBe(1)
            ->and($occupancy[$line->id][$taskDate->toDateString()]['used'])->toBe(0);
    });

    it('excludes soft deleted productions from occupancy', function () {
        $line = ProductionLine::factory()->create(['daily_batch_capacity' => 2]);
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

        $deletedProduction->delete();

        $occupancy = app(LineDayOccupancyService::class)->getOccupancy([$line->id], $date->copy(), $date->copy());

        expect($occupancy[$line->id][$date->toDateString()]['used'])->toBe(1);
    });
});
