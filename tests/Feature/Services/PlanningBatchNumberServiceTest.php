<?php

use App\Models\Production\Production;
use App\Services\Production\PlanningBatchNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates short planning references with T prefix', function () {
    $service = app(PlanningBatchNumberService::class);

    $first = $service->generateNextReference();
    $second = $service->generateNextReference();

    expect($first)->toBe('T00001')
        ->and($second)->toBe('T00002');
});

it('starts after highest existing T reference', function () {
    Production::factory()->create(['batch_number' => 'T00027']);
    Production::factory()->create(['batch_number' => 'B-LEGACY-001']);

    $next = app(PlanningBatchNumberService::class)->generateNextReference();

    expect($next)->toBe('T00028');
});
