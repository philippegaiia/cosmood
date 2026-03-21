<?php

use App\Models\Production\Production;
use App\Models\ResourceLock;
use App\Models\User;
use App\Services\OptimisticLocking\ResourcePresenceLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('acquires a new lock for the first editor', function () {
    $service = app(ResourcePresenceLockService::class);
    $user = User::factory()->create();
    $production = Production::factory()->create();

    $result = $service->acquire($production, $user, 'token-a');

    expect($result['status'])->toBe('acquired')
        ->and(ResourceLock::query()->count())->toBe(1)
        ->and(ResourceLock::query()->first()?->user_id)->toBe($user->id);
});

it('blocks a different user while an active lock exists', function () {
    $service = app(ResourcePresenceLockService::class);
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $production = Production::factory()->create();

    $service->acquire($production, $firstUser, 'token-a');
    $result = $service->acquire($production, $secondUser, 'token-b');

    expect($result['status'])->toBe('blocked')
        ->and(ResourceLock::query()->first()?->user_id)->toBe($firstUser->id);
});

it('transfers the lock to the newest tab for the same user', function () {
    $service = app(ResourcePresenceLockService::class);
    $user = User::factory()->create();
    $production = Production::factory()->create();

    $service->acquire($production, $user, 'token-a');
    $result = $service->acquire($production, $user, 'token-b');

    expect($result['status'])->toBe('transferred')
        ->and(ResourceLock::query()->first()?->token)->toBe('token-b');
});

it('reacquires an expired lock for a new user', function () {
    $service = app(ResourcePresenceLockService::class);
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $production = Production::factory()->create();

    $service->acquire($production, $firstUser, 'token-a');

    ResourceLock::query()->update([
        'expires_at' => now()->subMinute(),
    ]);

    $result = $service->heartbeat($production, $secondUser, 'token-b');

    expect($result['status'])->toBe('reacquired')
        ->and(ResourceLock::query()->first()?->user_id)->toBe($secondUser->id)
        ->and(ResourceLock::query()->first()?->token)->toBe('token-b');
});

it('force releases a lock', function () {
    $service = app(ResourcePresenceLockService::class);
    $owner = User::factory()->create();
    $manager = User::factory()->create();
    $production = Production::factory()->create();

    $service->acquire($production, $owner, 'token-a');
    $service->forceRelease($production, $manager, 'test');

    expect(ResourceLock::query()->count())->toBe(0);
});
