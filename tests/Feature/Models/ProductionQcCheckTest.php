<?php

use App\Enums\QcInputType;
use App\Enums\QcResult;
use App\Models\Production\ProductionQcCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('evaluates numeric checks with min and max limits', function () {
    $check = ProductionQcCheck::factory()->create([
        'input_type' => QcInputType::Number,
        'min_value' => 8.5,
        'max_value' => 10.5,
        'value_number' => null,
    ]);

    expect($check->fresh()->result)->toBe(QcResult::Pending);

    $check->update(['value_number' => 9.2]);
    expect($check->fresh()->result)->toBe(QcResult::Pass);

    $check->update(['value_number' => 11.2]);
    expect($check->fresh()->result)->toBe(QcResult::Fail);
});

it('evaluates boolean checks against target value', function () {
    $check = ProductionQcCheck::factory()->create([
        'input_type' => QcInputType::Boolean,
        'target_value' => 'true',
        'value_boolean' => true,
    ]);

    expect($check->fresh()->result)->toBe(QcResult::Pass);

    $check->update(['value_boolean' => false]);

    expect($check->fresh()->result)->toBe(QcResult::Fail);
});

it('marks non-required empty check as not applicable', function () {
    $check = ProductionQcCheck::factory()->create([
        'required' => false,
        'value_number' => null,
        'value_text' => null,
        'value_boolean' => null,
    ]);

    expect($check->fresh()->result)->toBe(QcResult::NotApplicable);
});

it('tracks completion as done or non done independently from qc result', function () {
    $check = ProductionQcCheck::factory()->create([
        'required' => true,
        'value_number' => null,
        'checked_at' => null,
    ]);

    expect($check->fresh()->isDone())->toBeFalse()
        ->and($check->fresh()->getCompletionLabel())->toBe('Non fait');

    $check->update([
        'checked_at' => now(),
    ]);

    expect($check->fresh()->isDone())->toBeTrue()
        ->and($check->fresh()->getCompletionLabel())->toBe('Fait');
});
