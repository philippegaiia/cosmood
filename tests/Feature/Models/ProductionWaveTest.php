<?php

use App\Enums\WaveStatus;
use App\Models\Production\Destination;
use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Models\User;

describe('ProductionWave Model', function () {
    it('can be created with factory', function () {
        $wave = ProductionWave::factory()->create();

        expect($wave)
            ->toBeInstanceOf(ProductionWave::class)
            ->and($wave->name)->not->toBeEmpty()
            ->and($wave->slug)->not->toBeEmpty();
    });

    it('has draft status by default', function () {
        $wave = ProductionWave::factory()->create();

        expect($wave->status)->toBe(WaveStatus::Draft);
    });

    it('can be approved', function () {
        $user = User::factory()->create();
        $wave = ProductionWave::factory()->approved()->create([
            'approved_by' => $user->id,
        ]);

        expect($wave->status)->toBe(WaveStatus::Approved)
            ->and($wave->approved_by)->toBe($user->id)
            ->and($wave->approved_at)->not->toBeNull()
            ->and($wave->planned_start_date)->not->toBeNull()
            ->and($wave->planned_end_date)->not->toBeNull();
    });

    it('can be in progress', function () {
        $wave = ProductionWave::factory()->inProgress()->create();

        expect($wave->status)->toBe(WaveStatus::InProgress)
            ->and($wave->started_at)->not->toBeNull();
    });

    it('can be completed', function () {
        $wave = ProductionWave::factory()->completed()->create();

        expect($wave->status)->toBe(WaveStatus::Completed)
            ->and($wave->completed_at)->not->toBeNull();
    });

    it('can have productions', function () {
        $wave = ProductionWave::factory()->create();
        Production::factory()->count(3)->forWave($wave)->create();

        expect($wave->productions)->toHaveCount(3);
    });

    it('can have a default destination', function () {
        $destination = Destination::factory()->create();
        $wave = ProductionWave::factory()->forDefaultDestination($destination)->create();

        expect($wave->defaultDestination)->not->toBeNull()
            ->and($wave->defaultDestination->id)->toBe($destination->id);
    });

    it('can check status', function () {
        $draftWave = ProductionWave::factory()->draft()->create();
        $approvedWave = ProductionWave::factory()->approved()->create();
        $inProgressWave = ProductionWave::factory()->inProgress()->create();
        $completedWave = ProductionWave::factory()->completed()->create();

        expect($draftWave->isDraft())->toBeTrue()
            ->and($draftWave->isApproved())->toBeFalse()
            ->and($approvedWave->isApproved())->toBeTrue()
            ->and($inProgressWave->isInProgress())->toBeTrue()
            ->and($completedWave->isCompleted())->toBeTrue();
    });
});

describe('ProductionWave Status Transitions', function () {
    it('can transition from draft to approved', function () {
        $wave = ProductionWave::factory()->draft()->create();
        $user = User::factory()->create();

        $wave->update([
            'status' => WaveStatus::Approved,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'planned_start_date' => now()->addDays(7),
            'planned_end_date' => now()->addDays(14),
        ]);

        expect($wave->fresh()->status)->toBe(WaveStatus::Approved);
    });

    it('can be cancelled', function () {
        $wave = ProductionWave::factory()->approved()->create();

        $wave->update(['status' => WaveStatus::Cancelled]);

        expect($wave->fresh()->status)->toBe(WaveStatus::Cancelled);
    });

    it('cannot complete while linked productions are still active', function () {
        $wave = ProductionWave::factory()->approved()->create();
        Production::factory()->forWave($wave)->planned()->create();
        $wave->update(['status' => WaveStatus::InProgress]);

        expect(fn () => $wave->complete())
            ->toThrow(InvalidArgumentException::class, 'Impossible de terminer la vague');
    });

    it('can complete when all linked productions are terminal', function () {
        $wave = ProductionWave::factory()->approved()->create();
        Production::factory()->forWave($wave)->finished()->create();
        Production::factory()->forWave($wave)->cancelled()->create();
        $wave->update(['status' => WaveStatus::InProgress]);

        $wave->complete();

        expect($wave->fresh()->status)->toBe(WaveStatus::Completed);
    });
});
