@php
    $plugin = \Saade\FilamentFullCalendar\FilamentFullCalendarPlugin::get();
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <style>
            .filament-fullcalendar .fc .fc-col-header-cell.fc-day-sat,
            .filament-fullcalendar .fc .fc-col-header-cell.fc-day-sun,
            .filament-fullcalendar .fc .fc-daygrid-day.fc-day-sat,
            .filament-fullcalendar .fc .fc-daygrid-day.fc-day-sun {
                background: #e5e7eb !important;
                color: #4b5563 !important;
            }

            .filament-fullcalendar .fc .fc-daygrid-day.fc-day-sat .fc-daygrid-day-number,
            .filament-fullcalendar .fc .fc-daygrid-day.fc-day-sun .fc-daygrid-day-number,
            .filament-fullcalendar .fc .fc-col-header-cell.fc-day-sat .fc-col-header-cell-cushion,
            .filament-fullcalendar .fc .fc-col-header-cell.fc-day-sun .fc-col-header-cell-cushion {
                color: #4b5563 !important;
            }

            .filament-fullcalendar .fc .fc-col-header-cell.fc-day-sat,
            .filament-fullcalendar .fc .fc-col-header-cell.fc-day-sun,
            .filament-fullcalendar .fc .fc-daygrid-day.fc-day-sat,
            .filament-fullcalendar .fc .fc-daygrid-day.fc-day-sun {
                width: 8% !important;
            }

            .filament-fullcalendar .fc .fc-col-header-cell:not(.fc-day-sat):not(.fc-day-sun),
            .filament-fullcalendar .fc .fc-daygrid-day:not(.fc-day-sat):not(.fc-day-sun) {
                width: 16.8% !important;
            }
        </style>

        <div class="flex justify-end flex-1 mb-4">
            <x-filament::actions :actions="$this->getCachedHeaderActions()" class="shrink-0" />
        </div>

        <div
            wire:ignore
            x-load
            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('filament-fullcalendar-alpine', 'saade/filament-fullcalendar') }}"
            x-ignore
            x-data="fullcalendar({
                locale: @js($plugin->getLocale()),
                plugins: @js($plugin->getPlugins()),
                schedulerLicenseKey: @js($plugin->getSchedulerLicenseKey()),
                timeZone: @js($plugin->getTimezone()),
                config: @js($this->getConfig()),
                editable: @json($plugin->isEditable()),
                selectable: @json($plugin->isSelectable()),
                eventClassNames: {!! htmlspecialchars($this->eventClassNames(), ENT_COMPAT) !!},
                eventContent: {!! htmlspecialchars($this->eventContent(), ENT_COMPAT) !!},
                eventDidMount: {!! htmlspecialchars($this->eventDidMount(), ENT_COMPAT) !!},
                eventWillUnmount: {!! htmlspecialchars($this->eventWillUnmount(), ENT_COMPAT) !!},
            })"
            class="filament-fullcalendar"
        ></div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
