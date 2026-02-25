<?php

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Services\Production\PermanentBatchNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns sequential permanent batch numbers with locking-safe service', function () {
    $first = Production::factory()->planned()->create([
        'permanent_batch_number' => null,
    ]);
    $second = Production::factory()->planned()->create([
        'permanent_batch_number' => null,
    ]);

    $service = app(PermanentBatchNumberService::class);

    expect($service->assignIfMissing($first))->toBe('00001')
        ->and($service->assignIfMissing($second))->toBe('00002');
});

it('auto assigns permanent batch number when production becomes ongoing', function () {
    $production = Production::factory()->confirmed()->create([
        'permanent_batch_number' => null,
    ]);

    $production->update([
        'status' => ProductionStatus::Ongoing,
    ]);

    expect($production->fresh()->permanent_batch_number)->toBe('00001');
});

it('bulk assigns by production chronology and skips pre-numbered records', function () {
    $later = Production::factory()->confirmed()->create([
        'production_date' => now()->addDays(2)->toDateString(),
        'permanent_batch_number' => null,
    ]);

    $earlier = Production::factory()->confirmed()->create([
        'production_date' => now()->addDay()->toDateString(),
        'permanent_batch_number' => null,
    ]);

    $preNumbered = Production::factory()->confirmed()->create([
        'production_date' => now()->addDays(3)->toDateString(),
        'permanent_batch_number' => '01200',
    ]);

    $assigned = app(PermanentBatchNumberService::class)->assignForProductions([
        $later->id,
        $earlier->id,
        $preNumbered->id,
    ]);

    expect($assigned)->toBe(2)
        ->and($earlier->fresh()->permanent_batch_number)->toBe('00001')
        ->and($later->fresh()->permanent_batch_number)->toBe('00002')
        ->and($preNumbered->fresh()->permanent_batch_number)->toBe('01200');
});
