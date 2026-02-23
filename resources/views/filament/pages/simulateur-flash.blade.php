<x-filament-panels::page>
    @once
        @vite(['resources/css/app.css'])
        @fluxScripts
    @endonce

    <livewire:simulateur-flash-page />
</x-filament-panels::page>
