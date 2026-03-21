<?php

namespace App\Filament\Traits;

use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

trait UsesOptimisticLocking
{
    #[Locked]
    public int $loadedLockVersion = 0;

    public bool $hasExternalUpdate = false;

    public bool $showingLockConflictModal = false;

    protected static bool $optimisticLockingPollingEnabled = true;

    protected static string $optimisticLockingPollInterval = '30s';

    /**
     * Initialize the lock version from the loaded record.
     *
     * Call this from afterFill() in your EditRecord page.
     */
    protected function initializeOptimisticLocking(): void
    {
        $this->loadedLockVersion = (int) ($this->record->lock_version ?? 0);
        $this->hasExternalUpdate = false;
        $this->showingLockConflictModal = false;
    }

    /**
     * Refresh the lock version after a successful save.
     *
     * Call this from afterSave() in your EditRecord page.
     * This prevents the user's own subsequent saves from conflicting.
     */
    protected function refreshLockVersionAfterSave(): void
    {
        $this->record->refresh();
        $this->loadedLockVersion = (int) ($this->record->lock_version ?? 0);
        $this->hasExternalUpdate = false;
    }

    /**
     * Poll the database to detect external updates while editing.
     *
     * Called automatically by Livewire polling (configured in getLockVersionPollingView()).
     */
    public function checkForExternalUpdates(): void
    {
        if ($this->hasExternalUpdate) {
            return;
        }

        $currentVersion = (int) $this->record->newQuery()
            ->whereKey($this->record->getKey())
            ->value('lock_version');

        if ($currentVersion !== $this->loadedLockVersion) {
            $this->hasExternalUpdate = true;

            Notification::make()
                ->warning()
                ->title(__('optimistic-locking.warning_title'))
                ->body(__('optimistic-locking.warning_body'))
                ->persistent()
                ->send();
        }
    }

    /**
     * Assert that no concurrent modification has occurred.
     *
     * This method uses atomic database operations to detect conflicts.
     * If a conflict is detected, it throws Halt and shows an error.
     *
     * @throws Halt When a conflict is detected
     */
    protected function assertNoConcurrentModification(): void
    {
        $model = $this->record;
        $keyName = $model->getKeyName();
        $tableName = $model->getTable();

        $currentVersion = (int) DB::table($tableName)
            ->where($keyName, $model->getKey())
            ->value('lock_version');

        if ($currentVersion !== $this->loadedLockVersion) {
            $this->showLockConflictModal($currentVersion);

            throw new Halt;
        }
    }

    /**
     * Persist the record using a row lock so the version check and write stay atomic.
     *
     * This closes the race window between pre-save validation and the actual update.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdateWithOptimisticLock(Model $record, array $data): Model
    {
        /** @var Model $lockedRecord */
        $lockedRecord = $record->newQuery()
            ->lockForUpdate()
            ->findOrFail($record->getKey());

        $currentVersion = (int) ($lockedRecord->getAttribute('lock_version') ?? 0);

        if ($currentVersion !== $this->loadedLockVersion) {
            $this->showLockConflictModal($currentVersion);

            throw new Halt;
        }

        $lockedRecord->fill($data);
        $lockedRecord->save();

        $this->record = $lockedRecord;

        return $lockedRecord;
    }

    /**
     * Show the lock conflict modal with options to discard or force save.
     */
    protected function showLockConflictModal(int $currentVersion = 0): void
    {
        $this->showingLockConflictModal = true;

        $body = $currentVersion > 0
            ? __('optimistic-locking.conflict_body').' '.__('Version actuelle: :version.', ['version' => $currentVersion])
            : __('optimistic-locking.conflict_body');

        Notification::make()
            ->danger()
            ->title(__('optimistic-locking.conflict_title'))
            ->body($body)
            ->persistent()
            ->send();
    }

    /**
     * Increment the lock version for the save operation.
     *
     * Call this from mutateFormDataBeforeSave() when not using atomic updates.
     */
    protected function incrementLockVersion(array &$data): void
    {
        $data['lock_version'] = $this->loadedLockVersion + 1;
    }

    /**
     * Force save despite version conflict (admin only).
     *
     * Reloads the current version and allows the save to proceed.
     */
    public function forceSave(): void
    {
        $this->record->refresh();
        $this->loadedLockVersion = (int) ($this->record->lock_version ?? 0);
        $this->hasExternalUpdate = false;
        $this->showingLockConflictModal = false;
    }

    /**
     * Reload the record and refill the form from the latest database state.
     */
    public function reloadRecordFromDatabase(): void
    {
        $this->record->refresh();

        if (method_exists($this, 'fillForm')) {
            $this->fillForm();

            return;
        }

        $this->form->fill(
            $this->mutateFormDataBeforeFill($this->record->attributesToArray())
        );

        $this->initializeOptimisticLocking();
    }

    /**
     * Get the current lock version from the loaded record.
     */
    public function getLoadedLockVersion(): int
    {
        return $this->loadedLockVersion;
    }

    /**
     * Check if an external update has been detected.
     */
    public function hasExternalUpdateDetected(): bool
    {
        return $this->hasExternalUpdate;
    }

    /**
     * Get the polling interval for external update checks.
     *
     * Override this in your Edit page to customize the polling interval.
     */
    public function getOptimisticLockingPollInterval(): string
    {
        return static::$optimisticLockingPollInterval;
    }

    /**
     * Get additional CSS classes for the warning banner.
     *
     * Override this in your Edit page to customize styling.
     */
    protected function getExternalUpdateWarningClasses(): string
    {
        return 'fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-6';
    }
}
