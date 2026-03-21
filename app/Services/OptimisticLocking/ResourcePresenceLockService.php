<?php

namespace App\Services\OptimisticLocking;

use App\Models\ResourceLock;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ResourcePresenceLockService
{
    /**
     * Acquire or refresh the presence lock for a lockable record.
     *
     * @return array{status: 'acquired'|'transferred'|'reacquired'|'blocked', lock: ResourceLock}
     */
    public function acquire(Model $record, User $user, string $token, int $ttlSeconds = 90): array
    {
        return DB::transaction(fn (): array => $this->acquireWithinTransaction($record, $user, $token, $ttlSeconds));
    }

    /**
     * Refresh the heartbeat for the current owner.
     *
     * @return array{status: 'acquired'|'reacquired'|'blocked', lock: ResourceLock}
     */
    public function heartbeat(Model $record, User $user, string $token, int $ttlSeconds = 90): array
    {
        return DB::transaction(function () use ($record, $user, $token, $ttlSeconds): array {
            $lock = $this->getLockedQuery($record)->first();

            if (! $lock || $lock->isExpired()) {
                return $this->acquireWithinTransaction($record, $user, $token, $ttlSeconds);
            }

            if ($lock->isOwnedBy($user, $token)) {
                $this->refreshLock($lock, $user, $token, $ttlSeconds);

                return [
                    'status' => 'acquired',
                    'lock' => $lock->loadMissing('owner'),
                ];
            }

            return [
                'status' => 'blocked',
                'lock' => $lock->loadMissing('owner'),
            ];
        });
    }

    public function release(Model $record, User $user, string $token): void
    {
        DB::transaction(function () use ($record, $user, $token): void {
            $lock = $this->getLockedQuery($record)->first();

            if (! $lock || ! $lock->isOwnedBy($user, $token)) {
                return;
            }

            $lock->delete();
        });
    }

    public function forceRelease(Model $record, User $actor, string $reason): void
    {
        DB::transaction(function () use ($record, $actor, $reason): void {
            $lock = $this->getLockedQuery($record)->first();

            if (! $lock) {
                return;
            }

            logger()->warning('resource_lock_force_released', [
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'lockable_type' => $record->getMorphClass(),
                'lockable_id' => $record->getKey(),
                'locked_user_id' => $lock->user_id,
                'locked_user_name' => $lock->owner_name_snapshot,
                'reason' => $reason,
            ]);

            $lock->delete();
        });
    }

    public function current(Model $record): ?ResourceLock
    {
        return ResourceLock::query()
            ->where('lockable_type', $record->getMorphClass())
            ->where('lockable_id', $record->getKey())
            ->with('owner')
            ->first();
    }

    /**
     * @return array{status: 'acquired'|'transferred'|'reacquired'|'blocked', lock: ResourceLock}
     */
    private function acquireWithinTransaction(Model $record, User $user, string $token, int $ttlSeconds): array
    {
        $lock = $this->getLockedQuery($record)->first();

        if (! $lock) {
            try {
                $lock = $this->createLock($record, $user, $token, $ttlSeconds);

                return [
                    'status' => 'acquired',
                    'lock' => $lock->loadMissing('owner'),
                ];
            } catch (QueryException $exception) {
                $lock = $this->getLockedQuery($record)->first();

                if (! $lock) {
                    throw $exception;
                }
            }
        }

        if ($lock->isExpired()) {
            $this->refreshLock($lock, $user, $token, $ttlSeconds, resetAcquiredAt: true);

            return [
                'status' => 'reacquired',
                'lock' => $lock->loadMissing('owner'),
            ];
        }

        if ($lock->isOwnedBy($user, $token)) {
            $this->refreshLock($lock, $user, $token, $ttlSeconds);

            return [
                'status' => 'acquired',
                'lock' => $lock->loadMissing('owner'),
            ];
        }

        if ((int) $lock->user_id === (int) $user->id) {
            $this->refreshLock($lock, $user, $token, $ttlSeconds, resetAcquiredAt: true);

            return [
                'status' => 'transferred',
                'lock' => $lock->loadMissing('owner'),
            ];
        }

        return [
            'status' => 'blocked',
            'lock' => $lock->loadMissing('owner'),
        ];
    }

    private function createLock(Model $record, User $user, string $token, int $ttlSeconds): ResourceLock
    {
        $now = now();

        return ResourceLock::query()->create([
            'lockable_type' => $record->getMorphClass(),
            'lockable_id' => $record->getKey(),
            'user_id' => $user->id,
            'owner_name_snapshot' => $user->name,
            'token' => $token,
            'acquired_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addSeconds($ttlSeconds),
        ]);
    }

    private function refreshLock(ResourceLock $lock, User $user, string $token, int $ttlSeconds, bool $resetAcquiredAt = false): void
    {
        $now = now();

        $attributes = [
            'user_id' => $user->id,
            'owner_name_snapshot' => $user->name,
            'token' => $token,
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addSeconds($ttlSeconds),
        ];

        if ($resetAcquiredAt) {
            $attributes['acquired_at'] = $now;
        }

        $lock->forceFill($attributes)->save();
    }

    private function getLockedQuery(Model $record): Builder
    {
        return ResourceLock::query()
            ->where('lockable_type', $record->getMorphClass())
            ->where('lockable_id', $record->getKey())
            ->lockForUpdate();
    }
}
