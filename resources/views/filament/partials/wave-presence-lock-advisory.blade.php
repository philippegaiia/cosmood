<div wire:poll.{{ $pollInterval }}="refreshWavePresenceLockAdvisory">
    @if ($hasForeignWavePresenceLockAdvisory)
        <div class="fi-section mb-6 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" x-data x-cloak>
            <div class="fi-section-content flex flex-col gap-y-6 p-6">
                <div class="fi-notify flex items-center gap-x-3 rounded-lg bg-sky-50 p-4 text-sm font-medium ring-1 ring-sky-600/20 dark:bg-sky-500/10 dark:ring-sky-500/30">
                    <div class="fi-notify-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-sky-600 dark:text-sky-400">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 9.75h1.5v5.25h-1.5V9.75Zm0-3h1.5v1.5h-1.5v-1.5ZM4.5 12a7.5 7.5 0 1 1 15 0 7.5 7.5 0 0 1-15 0Z" />
                        </svg>
                    </div>
                    <div class="fi-notify-content flex-1">
                        <div class="font-medium text-sky-700 dark:text-sky-300">
                            {{ __('presence-locking.parent_wave_advisory_title') }}
                        </div>
                        <div class="mt-1 font-normal text-sky-800 dark:text-sky-200">
                            {{ $this->getWavePresenceLockAdvisoryBody() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
