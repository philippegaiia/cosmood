<?php

namespace App\Filament\Traits;

use App\Models\ResourceLock;
use App\Models\User;
use App\Services\OptimisticLocking\ResourcePresenceLockService;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

trait GuardsRelationManagerActionsWithPresenceLock
{
    protected static string $relationManagerPresenceLockPollInterval = '30s';

    protected function shouldBlockOwnerRecordMutationBecauseLocked(): bool
    {
        $lock = $this->getActiveForeignOwnerRecordPresenceLock();

        if (! $lock) {
            return false;
        }

        Notification::make()
            ->warning()
            ->title(__('presence-locking.action_blocked_title'))
            ->body(__('presence-locking.action_blocked_body', [
                'owner' => $lock->owner_name_snapshot !== ''
                    ? $lock->owner_name_snapshot
                    : ($lock->owner?->name ?? __('presence-locking.unknown_owner')),
                'since' => $lock->acquired_at?->diffForHumans() ?? __('presence-locking.just_now'),
            ]))
            ->persistent()
            ->send();

        return true;
    }

    protected function getRelationManagerPresenceLockPollInterval(): string
    {
        return static::$relationManagerPresenceLockPollInterval;
    }

    private function getActiveForeignOwnerRecordPresenceLock(): ?ResourceLock
    {
        $ownerRecord = $this->getOwnerRecord();

        if (! $ownerRecord instanceof Model) {
            return null;
        }

        $lock = app(ResourcePresenceLockService::class)->current($ownerRecord);

        if (! $lock || $lock->isExpired()) {
            return null;
        }

        $user = auth()->user();

        if ($user instanceof User && (int) $lock->user_id === (int) $user->id) {
            return null;
        }

        return $lock;
    }
}
