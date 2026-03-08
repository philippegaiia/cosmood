<?php

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Services\Production\ProductionStatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function productionStatusTransitionService(): ProductionStatusTransitionService
{
    return app(ProductionStatusTransitionService::class);
}

it('confirms only planned productions and skips others', function () {
    $plannedA = Production::factory()->planned()->create();
    $plannedB = Production::factory()->planned()->create();
    $alreadyConfirmed = Production::factory()->confirmed()->create();
    $cancelled = Production::factory()->cancelled()->create();

    $summary = productionStatusTransitionService()->confirmPlannedProductions(collect([
        $plannedA,
        $plannedB,
        $alreadyConfirmed,
        $cancelled,
    ]));

    expect($summary['confirmed'])->toBe(2)
        ->and($summary['skipped'])->toBe(2)
        ->and($summary['failed'])->toBe(0)
        ->and($plannedA->fresh()->status)->toBe(ProductionStatus::Confirmed)
        ->and($plannedB->fresh()->status)->toBe(ProductionStatus::Confirmed)
        ->and($alreadyConfirmed->fresh()->status)->toBe(ProductionStatus::Confirmed)
        ->and($cancelled->fresh()->status)->toBe(ProductionStatus::Cancelled);
});
