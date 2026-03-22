<?php

use App\Models\Production\Product;
use App\Models\ResourceLock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prunes expired resource locks', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $expiredLock = ResourceLock::query()->create([
        'lockable_type' => $product->getMorphClass(),
        'lockable_id' => $product->id,
        'user_id' => $user->id,
        'owner_name_snapshot' => $user->name,
        'token' => str_repeat('a', 64),
        'acquired_at' => now()->subHour(),
        'last_seen_at' => now()->subMinutes(45),
        'expires_at' => now()->subMinute(),
    ]);

    $freshProduct = Product::factory()->create();

    $activeLock = ResourceLock::query()->create([
        'lockable_type' => $freshProduct->getMorphClass(),
        'lockable_id' => $freshProduct->id,
        'user_id' => $user->id,
        'owner_name_snapshot' => $user->name,
        'token' => str_repeat('b', 64),
        'acquired_at' => now()->subHour(),
        'last_seen_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $this->artisan('locks:prune-expired')
        ->expectsOutput('Pruned 1 expired lock(s).')
        ->assertSuccessful();

    expect(ResourceLock::query()->whereKey($expiredLock->id)->exists())->toBeFalse()
        ->and(ResourceLock::query()->whereKey($activeLock->id)->exists())->toBeTrue();
});
