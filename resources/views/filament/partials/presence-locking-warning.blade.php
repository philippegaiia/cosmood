<div wire:poll.{{ $pollInterval }}="refreshPresenceLockHeartbeat">
    @if ($hasForeignPresenceLock)
        <div class="fi-section mb-6 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" x-data x-cloak>
            <div class="fi-section-content flex flex-col gap-y-6 p-6">
                <div class="fi-notify flex items-center gap-x-3 rounded-lg bg-rose-50 p-4 text-sm font-medium ring-1 ring-rose-600/20 dark:bg-rose-500/10 dark:ring-rose-500/30">
                    <div class="fi-notify-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-rose-600 dark:text-rose-400">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636a9 9 0 1 1-12.728 0 9 9 0 0 1 12.728 0ZM12 8.25v4.5m0 3h.008v.008H12v-.008Z" />
                        </svg>
                    </div>
                    <div class="fi-notify-content flex-1">
                        <div class="font-medium text-rose-600 dark:text-rose-400">
                            {{ __('presence-locking.blocked_title') }}
                        </div>
                        <div class="mt-1 text-rose-700 dark:text-rose-300 font-normal">
                            {{ $this->getPresenceLockBannerBody() }}
                        </div>
                    </div>
                    <div class="fi-notify-actions flex gap-x-2">
                        <button
                            type="button"
                            wire:click="retryPresenceLockAcquisition"
                            class="fi-btn flex items-center justify-center gap-x-2 rounded-lg bg-rose-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-rose-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 dark:bg-rose-500 dark:hover:bg-rose-400"
                        >
                            {{ __('presence-locking.retry_button') }}
                        </button>

                        @if ($this->canForceReleasePresenceLock())
                            <button
                                type="button"
                                wire:click="forceReleasePresenceLock"
                                class="fi-btn flex items-center justify-center gap-x-2 rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-rose-700 shadow-sm ring-1 ring-rose-300 hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 dark:bg-gray-900 dark:text-rose-300 dark:ring-rose-500/40 dark:hover:bg-rose-500/10"
                            >
                                {{ __('presence-locking.force_unlock_button') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
