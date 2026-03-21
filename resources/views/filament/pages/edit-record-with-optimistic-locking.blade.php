<x-filament-panels::page>
    @if (method_exists($this, 'shouldShowWavePresenceLockAdvisory') && method_exists($this, 'getWavePresenceLockAdvisoryPollInterval'))
        @include('filament.partials.wave-presence-lock-advisory', [
            'hasForeignWavePresenceLockAdvisory' => $this->shouldShowWavePresenceLockAdvisory(),
            'pollInterval' => $this->getWavePresenceLockAdvisoryPollInterval(),
        ])
    @endif

    @include('filament.partials.presence-locking-warning', [
        'hasForeignPresenceLock' => $this->shouldShowPresenceLockBanner(),
        'pollInterval' => $this->getPresenceLockPollInterval(),
    ])

    @if (! $this->shouldBlockEditContentForPresenceLock())
    @include('filament.partials.optimistic-locking-warning', [
        'hasExternalUpdate' => $this->hasExternalUpdateDetected(),
        'pollInterval' => $this->getOptimisticLockingPollInterval(),
        'warningClasses' => $this->getExternalUpdateWarningClasses(),
    ])

    {{ $this->content }}
    @endif
</x-filament-panels::page>
