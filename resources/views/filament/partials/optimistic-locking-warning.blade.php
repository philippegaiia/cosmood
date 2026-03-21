<div wire:poll.{{ $pollInterval }}="checkForExternalUpdates">
    @if ($hasExternalUpdate)
        <div class="{{ $warningClasses }}" x-data x-cloak>
            <div class="fi-section-content flex flex-col gap-y-6 p-6">
                <div class="fi-notify flex items-center gap-x-3 rounded-lg bg-amber-50 p-4 text-sm font-medium ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:ring-amber-500/30">
                    <div class="fi-notify-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-amber-600 dark:text-amber-400">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div class="fi-notify-content flex-1">
                        <div class="font-medium text-amber-600 dark:text-amber-400">
                            {{ __('optimistic-locking.warning_title') }}
                        </div>
                        <div class="mt-1 text-amber-700 dark:text-amber-300 font-normal">
                            {{ __('optimistic-locking.warning_body') }}
                        </div>
                    </div>
                    <div class="fi-notify-actions flex gap-x-2">
                        <button
                            type="button"
                            wire:click="reloadRecordFromDatabase"
                            class="fi-btn flex items-center justify-center gap-x-2 rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 dark:bg-amber-500 dark:hover:bg-amber-400"
                        >
                            {{ __('optimistic-locking.refresh_button') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
