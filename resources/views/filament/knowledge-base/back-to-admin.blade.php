<a
    href="{{ \Filament\Facades\Filament::getDefaultPanel()->getUrl() }}"
    class="fi-btn fi-color-gray fi-size-sm inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/5"
>
    <x-filament::icon icon="heroicon-o-arrow-left-end-on-rectangle" class="h-5 w-5" />
    <span>{{ __('filament-knowledge-base::translations.back-to-default-panel') }}</span>
</a>
