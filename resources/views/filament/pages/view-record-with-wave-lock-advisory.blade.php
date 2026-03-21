<x-filament-panels::page>
    @include('filament.partials.wave-presence-lock-advisory', [
        'hasForeignWavePresenceLockAdvisory' => $this->shouldShowWavePresenceLockAdvisory(),
        'pollInterval' => $this->getWavePresenceLockAdvisoryPollInterval(),
    ])

    {{ $this->content }}
</x-filament-panels::page>
