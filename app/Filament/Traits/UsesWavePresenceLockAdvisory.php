<?php

namespace App\Filament\Traits;

use App\Models\Production\ProductionWave;
use App\Models\ResourceLock;
use App\Models\User;
use App\Services\OptimisticLocking\ResourcePresenceLockService;
use Illuminate\Database\Eloquent\Model;

trait UsesWavePresenceLockAdvisory
{
    public bool $hasForeignWavePresenceLockAdvisory = false;

    public ?string $wavePresenceLockAdvisoryOwnerName = null;

    public ?string $wavePresenceLockAdvisorySinceLabel = null;

    protected static string $wavePresenceLockAdvisoryPollInterval = '30s';

    protected function initializeWavePresenceLockAdvisory(): void
    {
        $this->refreshWavePresenceLockAdvisory();
    }

    public function refreshWavePresenceLockAdvisory(): void
    {
        $lock = $this->getActiveForeignWavePresenceLock();

        $this->hasForeignWavePresenceLockAdvisory = $lock !== null;
        $this->wavePresenceLockAdvisoryOwnerName = $lock?->owner_name_snapshot !== ''
            ? $lock?->owner_name_snapshot
            : ($lock?->owner?->name ?? null);
        $this->wavePresenceLockAdvisorySinceLabel = $lock?->acquired_at?->diffForHumans();
    }

    public function shouldShowWavePresenceLockAdvisory(): bool
    {
        return $this->hasForeignWavePresenceLockAdvisory;
    }

    public function getWavePresenceLockAdvisoryPollInterval(): string
    {
        return static::$wavePresenceLockAdvisoryPollInterval;
    }

    public function getWavePresenceLockAdvisoryBody(): string
    {
        return __('presence-locking.parent_wave_advisory_body', [
            'owner' => $this->wavePresenceLockAdvisoryOwnerName ?? __('presence-locking.unknown_owner'),
            'since' => $this->wavePresenceLockAdvisorySinceLabel ?? __('presence-locking.just_now'),
        ]);
    }

    private function getActiveForeignWavePresenceLock(): ?ResourceLock
    {
        $wave = $this->resolveCurrentWaveRecord();

        if (! $wave) {
            return null;
        }

        $lock = app(ResourcePresenceLockService::class)->current($wave);

        if (! $lock || $lock->isExpired()) {
            return null;
        }

        $user = auth()->user();

        if ($user instanceof User && (int) $lock->user_id === (int) $user->id) {
            return null;
        }

        return $lock;
    }

    private function resolveCurrentWaveRecord(): ?ProductionWave
    {
        $record = $this->record ?? null;

        if (! $record instanceof Model) {
            return null;
        }

        $waveId = (int) ($record->newQuery()
            ->whereKey($record->getKey())
            ->value('production_wave_id') ?? 0);

        if ($waveId <= 0) {
            return null;
        }

        return ProductionWave::query()->find($waveId);
    }
}
