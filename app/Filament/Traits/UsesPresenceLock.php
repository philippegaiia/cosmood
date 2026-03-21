<?php

namespace App\Filament\Traits;

use App\Models\ResourceLock;
use App\Models\User;
use App\Services\OptimisticLocking\ResourcePresenceLockService;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

trait UsesPresenceLock
{
    #[Locked]
    public string $presenceLockToken = '';

    public bool $hasForeignPresenceLock = false;

    public bool $presenceLockOwnedByCurrentUser = false;

    public ?string $presenceLockOwnerName = null;

    public ?string $presenceLockSinceLabel = null;

    protected static string $presenceLockPollInterval = '15s';

    protected static int $presenceLockTtlSeconds = 90;

    protected function initializePresenceLocking(): void
    {
        $user = $this->getPresenceLockUser();

        if (! $user) {
            return;
        }

        if ($this->presenceLockToken === '') {
            $this->presenceLockToken = (string) Str::uuid();
        }

        $this->syncPresenceLockState(
            $this->getPresenceLockService()->acquire(
                $this->record,
                $user,
                $this->presenceLockToken,
                static::$presenceLockTtlSeconds,
            ),
            notifyOnBlock: true,
            notifyOnAcquire: false,
        );
    }

    public function refreshPresenceLockHeartbeat(): void
    {
        $user = $this->getPresenceLockUser();

        if (! $user || $this->presenceLockToken === '') {
            return;
        }

        $this->syncPresenceLockState(
            $this->getPresenceLockService()->heartbeat(
                $this->record,
                $user,
                $this->presenceLockToken,
                static::$presenceLockTtlSeconds,
            ),
        );
    }

    public function retryPresenceLockAcquisition(): void
    {
        $user = $this->getPresenceLockUser();

        if (! $user || $this->presenceLockToken === '') {
            return;
        }

        $this->syncPresenceLockState(
            $this->getPresenceLockService()->acquire(
                $this->record,
                $user,
                $this->presenceLockToken,
                static::$presenceLockTtlSeconds,
            ),
        );
    }

    public function forceReleasePresenceLock(): void
    {
        $user = $this->getPresenceLockUser();

        if (! $user || ! $this->canForceReleasePresenceLock()) {
            return;
        }

        $this->getPresenceLockService()->forceRelease(
            $this->record,
            $user,
            __('presence-locking.force_release_reason'),
        );

        Notification::make()
            ->success()
            ->title(__('presence-locking.force_release_success_title'))
            ->body(__('presence-locking.force_release_success_body'))
            ->send();

        $this->retryPresenceLockAcquisition();
    }

    protected function ensurePresenceLockOwnership(): void
    {
        if (! $this->hasForeignPresenceLock) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(__('presence-locking.save_blocked_title'))
            ->body($this->getPresenceLockBannerBody())
            ->persistent()
            ->send();

        throw new Halt;
    }

    protected function releasePresenceLock(): void
    {
        $user = $this->getPresenceLockUser();

        if (! $user || $this->presenceLockToken === '') {
            return;
        }

        $this->getPresenceLockService()->release($this->record, $user, $this->presenceLockToken);
    }

    public function shouldBlockEditContentForPresenceLock(): bool
    {
        return $this->hasForeignPresenceLock;
    }

    public function shouldShowPresenceLockBanner(): bool
    {
        return $this->hasForeignPresenceLock;
    }

    public function getPresenceLockPollInterval(): string
    {
        return static::$presenceLockPollInterval;
    }

    public function canForceReleasePresenceLock(): bool
    {
        return $this->getPresenceLockUser()?->canForceReleaseResourceLocks() ?? false;
    }

    public function getPresenceLockBannerBody(): string
    {
        if ($this->presenceLockOwnedByCurrentUser) {
            return __('presence-locking.blocked_body_self', [
                'since' => $this->presenceLockSinceLabel ?? __('presence-locking.just_now'),
            ]);
        }

        return __('presence-locking.blocked_body', [
            'owner' => $this->presenceLockOwnerName ?? __('presence-locking.unknown_owner'),
            'since' => $this->presenceLockSinceLabel ?? __('presence-locking.just_now'),
        ]);
    }

    private function syncPresenceLockState(array $result, bool $notifyOnBlock = true, bool $notifyOnAcquire = true): void
    {
        $wasBlocked = $this->hasForeignPresenceLock;

        $this->mapPresenceLock($result['lock'] ?? null);

        $this->hasForeignPresenceLock = ($result['status'] ?? null) === 'blocked';

        if ($this->hasForeignPresenceLock && ! $wasBlocked && $notifyOnBlock) {
            Notification::make()
                ->warning()
                ->title(__('presence-locking.blocked_title'))
                ->body($this->getPresenceLockBannerBody())
                ->persistent()
                ->send();
        }

        if (! $this->hasForeignPresenceLock && $wasBlocked && $notifyOnAcquire) {
            Notification::make()
                ->success()
                ->title(__('presence-locking.acquired_title'))
                ->body(__('presence-locking.acquired_body'))
                ->send();
        }
    }

    private function mapPresenceLock(?ResourceLock $lock): void
    {
        if (! $lock) {
            $this->presenceLockOwnerName = null;
            $this->presenceLockOwnedByCurrentUser = false;
            $this->presenceLockSinceLabel = null;

            return;
        }

        $user = $this->getPresenceLockUser();

        $this->presenceLockOwnerName = $lock->owner_name_snapshot !== ''
            ? $lock->owner_name_snapshot
            : ($lock->owner?->name ?? null);

        $this->presenceLockOwnedByCurrentUser = $user !== null
            && (int) $lock->user_id === (int) $user->id;

        $this->presenceLockSinceLabel = $lock->acquired_at?->diffForHumans();
    }

    private function getPresenceLockService(): ResourcePresenceLockService
    {
        return app(ResourcePresenceLockService::class);
    }

    private function getPresenceLockUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
