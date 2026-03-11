<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-zinc-950 dark:text-white">{{ __('Urgences') }}</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Lecture courte des exceptions du jour. Pas une liste exhaustive.') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <x-filament::badge :color="$headline_tone">
                    {{ trans_choice(':count point à traiter|:count points à traiter', $total_items, ['count' => $total_items]) }}
                </x-filament::badge>
                <x-filament::badge color="gray">{{ __('Manager') }}</x-filament::badge>
            </div>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2">
            @foreach ($sections as $section)
                @php
                    $isDanger = $section['tone'] === 'danger';
                    $cardClasses = $isDanger
                        ? 'border-red-200 bg-red-50/50 dark:border-red-900/50 dark:bg-red-950/20'
                        : 'border-amber-200 bg-amber-50/50 dark:border-amber-900/50 dark:bg-amber-950/20';
                    $pillClasses = $isDanger
                        ? 'bg-red-100 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-200 dark:ring-red-900/60'
                        : 'bg-amber-100 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-200 dark:ring-amber-900/60';
                    $itemClasses = $isDanger
                        ? 'border-red-100 hover:border-red-200 hover:bg-white/80 dark:border-red-900/50 dark:hover:border-red-800 dark:hover:bg-red-950/30'
                        : 'border-amber-100 hover:border-amber-200 hover:bg-white/80 dark:border-amber-900/50 dark:hover:border-amber-800 dark:hover:bg-amber-950/30';
                @endphp

                <div class="rounded-2xl border p-4 shadow-sm {{ $cardClasses }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-zinc-700 dark:text-zinc-200">
                                {{ $section['title'] }}
                            </h3>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $section['subtitle'] }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex min-w-8 justify-center rounded-full px-2 py-1 text-xs font-semibold ring-1 {{ $pillClasses }}">
                                {{ $section['items']->count() }}
                            </span>
                            <a
                                href="{{ $section['action_url'] }}"
                                class="text-xs font-medium text-zinc-600 underline decoration-zinc-300 underline-offset-4 transition hover:text-zinc-950 dark:text-zinc-300 dark:decoration-zinc-700 dark:hover:text-white"
                            >
                                {{ $section['action_label'] }}
                            </a>
                        </div>
                    </div>

                    <div class="mt-3 space-y-2">
                        @forelse ($section['items'] as $item)
                            <a
                                href="{{ $item['url'] }}"
                                class="block rounded-xl border px-3 py-2 transition {{ $itemClasses }}"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-zinc-950 dark:text-white">
                                            {{ $item['label'] }}
                                        </p>
                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $item['meta'] }}
                                        </p>
                                    </div>

                                    <x-filament::badge :color="$item['tone']">
                                        {{ $item['badge'] }}
                                    </x-filament::badge>
                                </div>
                            </a>
                        @empty
                            <div class="rounded-xl border border-dashed border-zinc-300/80 px-3 py-4 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                {{ $section['empty_state'] }}
                            </div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
