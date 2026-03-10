<?php

use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Models\Production\Formula;
use App\Models\Production\Holiday;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionWave;
use App\Services\Production\WaveProductionPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function waveProductionPlanningService(): WaveProductionPlanningService
{
    return app(WaveProductionPlanningService::class);
}

function createWavePlanningProduction(ProductionWave $wave, int $lineId, ProductionStatus $status, string $productionDate): Production
{
    $product = Product::factory()->create();

    $formula = Formula::query()->create([
        'name' => 'Formula '.Str::uuid(),
        'slug' => Str::slug('formula-'.Str::uuid()),
        'code' => 'FRM-'.Str::upper(Str::random(8)),
        'is_active' => true,
        'date_of_creation' => now()->toDateString(),
    ]);

    return Production::query()->create([
        'production_wave_id' => $wave->id,
        'production_line_id' => $lineId,
        'product_id' => $product->id,
        'formula_id' => $formula->id,
        'batch_number' => 'T'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'slug' => Str::slug('batch-'.Str::uuid()),
        'status' => $status,
        'sizing_mode' => SizingMode::OilWeight,
        'planned_quantity' => 10,
        'expected_units' => 100,
        'production_date' => $productionDate,
        'ready_date' => now()->addDays(2)->toDateString(),
        'organic' => true,
    ]);
}

it('plans dates independently per production line capacity', function (): void {
    $soapLine = ProductionLine::factory()->soapLine()->create([
        'daily_batch_capacity' => 4,
    ]);

    $deodorantLab = ProductionLine::factory()->deodorantLab()->create([
        'daily_batch_capacity' => 3,
    ]);

    $plannedDates = waveProductionPlanningService()->planBatchDates(
        batchPlans: [
            ['production_line_id' => $soapLine->id],
            ['production_line_id' => $soapLine->id],
            ['production_line_id' => $soapLine->id],
            ['production_line_id' => $soapLine->id],
            ['production_line_id' => $soapLine->id],
            ['production_line_id' => $deodorantLab->id],
            ['production_line_id' => $deodorantLab->id],
            ['production_line_id' => $deodorantLab->id],
            ['production_line_id' => $deodorantLab->id],
            ['production_line_id' => null],
            ['production_line_id' => null],
        ],
        startDate: '2026-03-09',
        skipWeekends: true,
        skipHolidays: true,
        fallbackDailyCapacity: 2,
    );

    expect($plannedDates)->toHaveCount(11)
        ->and($plannedDates[0]->toDateString())->toBe('2026-03-09')
        ->and($plannedDates[3]->toDateString())->toBe('2026-03-09')
        ->and($plannedDates[4]->toDateString())->toBe('2026-03-10')
        ->and($plannedDates[5]->toDateString())->toBe('2026-03-09')
        ->and($plannedDates[7]->toDateString())->toBe('2026-03-09')
        ->and($plannedDates[8]->toDateString())->toBe('2026-03-10')
        ->and($plannedDates[9]->toDateString())->toBe('2026-03-09')
        ->and($plannedDates[10]->toDateString())->toBe('2026-03-09');
});

it('skips weekends and holidays when planning dates', function (): void {
    Holiday::query()->create([
        'name' => 'Holiday test',
        'date' => '2026-03-10',
        'is_recurring' => false,
        'year' => 2026,
    ]);

    $plannedDates = waveProductionPlanningService()->planBatchDates(
        batchPlans: [
            ['production_line_id' => null],
            ['production_line_id' => null],
            ['production_line_id' => null],
        ],
        startDate: '2026-03-07',
        skipWeekends: true,
        skipHolidays: true,
        fallbackDailyCapacity: 1,
    );

    expect($plannedDates[0]->toDateString())->toBe('2026-03-09')
        ->and($plannedDates[1]->toDateString())->toBe('2026-03-11')
        ->and($plannedDates[2]->toDateString())->toBe('2026-03-12');
});

it('pushes new batches to the next available date when existing productions already occupy capacity', function (): void {
    $wave = ProductionWave::factory()->create();
    $line = ProductionLine::factory()->soapLine()->create([
        'daily_batch_capacity' => 2,
    ]);

    createWavePlanningProduction($wave, $line->id, ProductionStatus::Planned, '2026-03-09');
    createWavePlanningProduction($wave, $line->id, ProductionStatus::Confirmed, '2026-03-09');
    createWavePlanningProduction($wave, $line->id, ProductionStatus::Planned, '2026-03-10');

    $plannedDates = waveProductionPlanningService()->planBatchDates(
        batchPlans: [
            ['production_line_id' => $line->id],
            ['production_line_id' => $line->id],
        ],
        startDate: '2026-03-09',
        skipWeekends: true,
        skipHolidays: true,
        fallbackDailyCapacity: 4,
    );

    expect($plannedDates[0]->toDateString())->toBe('2026-03-10')
        ->and($plannedDates[1]->toDateString())->toBe('2026-03-11');
});

it('reschedules only planned and confirmed productions for a wave', function (): void {
    $wave = ProductionWave::factory()->create();
    $line = ProductionLine::factory()->soapLine()->create([
        'daily_batch_capacity' => 2,
    ]);

    $planned = createWavePlanningProduction($wave, $line->id, ProductionStatus::Planned, '2026-03-01');
    $confirmed = createWavePlanningProduction($wave, $line->id, ProductionStatus::Confirmed, '2026-03-02');
    $finished = createWavePlanningProduction($wave, $line->id, ProductionStatus::Finished, '2026-03-03');

    $summary = waveProductionPlanningService()->rescheduleWaveProductions(
        wave: $wave,
        startDate: '2026-03-09',
        skipWeekends: true,
        skipHolidays: true,
        fallbackDailyCapacity: 4,
    );

    expect($summary['planned_count'])->toBe(2)
        ->and($summary['planned_start_date'])->toBe('2026-03-09')
        ->and($summary['planned_end_date'])->toBe('2026-03-09')
        ->and($planned->fresh()->production_date?->toDateString())->toBe('2026-03-09')
        ->and($confirmed->fresh()->production_date?->toDateString())->toBe('2026-03-09')
        ->and($finished->fresh()->production_date?->toDateString())->toBe('2026-03-03')
        ->and($wave->fresh()->planned_start_date?->toDateString())->toBe('2026-03-09')
        ->and($wave->fresh()->planned_end_date?->toDateString())->toBe('2026-03-09');
});

it('replans selected productions without counting their current dates as occupied capacity', function (): void {
    $wave = ProductionWave::factory()->create();
    $line = ProductionLine::factory()->soapLine()->create([
        'daily_batch_capacity' => 2,
    ]);

    $selectedFirst = createWavePlanningProduction($wave, $line->id, ProductionStatus::Planned, '2026-03-09');
    $selectedSecond = createWavePlanningProduction($wave, $line->id, ProductionStatus::Confirmed, '2026-03-10');
    createWavePlanningProduction($wave, $line->id, ProductionStatus::Planned, '2026-03-09');

    $summary = waveProductionPlanningService()->rescheduleProductions(
        productions: collect([$selectedFirst, $selectedSecond]),
        startDate: '2026-03-09',
        skipWeekends: true,
        skipHolidays: true,
        fallbackDailyCapacity: 4,
    );

    expect($summary['rescheduled_count'])->toBe(2)
        ->and($selectedFirst->fresh()->production_date?->toDateString())->toBe('2026-03-09')
        ->and($selectedSecond->fresh()->production_date?->toDateString())->toBe('2026-03-10');
});

it('blocks replanning for in-progress waves', function (): void {
    $wave = ProductionWave::factory()->inProgress()->create();

    expect(fn () => waveProductionPlanningService()->rescheduleWaveProductions(
        wave: $wave,
        startDate: '2026-03-09',
        skipWeekends: true,
        skipHolidays: true,
        fallbackDailyCapacity: 4,
    ))->toThrow(\InvalidArgumentException::class, 'replanification est bloquée');
});
