<?php

namespace App\Filament\Widgets;

use App\Enums\ProductionStatus;
use App\Filament\Resources\Production\ProductionResource;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use Illuminate\Support\Carbon;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

class ProductionCalendarWidget extends FullCalendarWidget
{
    protected static ?string $heading = 'Calendrier production et tâches';

    protected string $view = 'filament.widgets.production-calendar-widget';

    protected int|string|array $columnSpan = 'full';

    public function config(): array
    {
        return [
            'initialView' => 'dayGridMonth',
            'firstDay' => 1,
            'height' => 'auto',
            'weekends' => true,
            'displayEventTime' => false,
            'displayEventEnd' => false,
            'eventDisplay' => 'block',
            'dayMaxEventRows' => 3,
            'dayHeaderFormat' => ['weekday' => 'short'],
            'headerToolbar' => [
                'left' => 'dayGridMonth,dayGridWeek,dayGridDay,listWeek',
                'center' => 'title',
                'right' => 'prev,next today',
            ],
        ];
    }

    public function fetchEvents(array $fetchInfo): array
    {
        $start = Carbon::parse($fetchInfo['start'])->startOfDay();
        $end = Carbon::parse($fetchInfo['end'])->endOfDay();

        $productionEvents = Production::query()
            ->with('product:id,name')
            ->whereBetween('production_date', [$start, $end])
            ->get()
            ->map(function (Production $production): array {
                $title = trim('B '.$production->getLotIdentifier().' '.($production->product?->name ? '- '.$production->product->name : ''));

                $eventColor = $this->getProductionEventColor($production);

                return [
                    'id' => 'production-'.$production->id,
                    'title' => $title,
                    'start' => $production->production_date?->toDateString(),
                    'allDay' => true,
                    'backgroundColor' => $eventColor,
                    'borderColor' => $eventColor,
                    'textColor' => '#ffffff',
                    'url' => ProductionResource::getUrl('edit', ['record' => $production]),
                    'extendedProps' => [
                        'type' => 'production',
                        'batch_number' => $production->getLotIdentifier(),
                    ],
                ];
            });

        $taskEvents = ProductionTask::query()
            ->with('production:id,batch_number,permanent_batch_number')
            ->whereBetween('scheduled_date', [$start, $end])
            ->get()
            ->map(function (ProductionTask $task): array {
                $title = trim('T '.($task->name ?? 'Sans nom').' - '.($task->production?->getLotIdentifier() ?? 'n/a'));

                $eventColor = $this->getTaskEventColor($task);

                return [
                    'id' => 'task-'.$task->id,
                    'title' => $title,
                    'start' => $task->scheduled_date?->toDateString(),
                    'allDay' => true,
                    'backgroundColor' => $eventColor,
                    'borderColor' => $eventColor,
                    'textColor' => '#ffffff',
                    'url' => $task->production ? ProductionResource::getUrl('edit', ['record' => $task->production]) : null,
                    'extendedProps' => [
                        'type' => 'task',
                        'task_id' => $task->id,
                    ],
                ];
            });

        return $productionEvents
            ->concat($taskEvents)
            ->values()
            ->all();
    }

    protected function headerActions(): array
    {
        return [];
    }

    protected function modalActions(): array
    {
        return [];
    }

    private function getProductionEventColor(Production $production): string
    {
        return match ($production->status) {
            ProductionStatus::Planned => '#334155',
            ProductionStatus::Confirmed => '#4338ca',
            ProductionStatus::Ongoing => '#b45309',
            ProductionStatus::Finished => '#4d7c0f',
            ProductionStatus::Cancelled => '#c2410c',
        };
    }

    private function getTaskEventColor(ProductionTask $task): string
    {
        if ($task->isCancelled()) {
            return '#6b7280';
        }

        if ($task->is_finished) {
            return '#166534';
        }

        if ($task->scheduled_date && $task->scheduled_date->isFuture()) {
            return '#b45309';
        }

        return '#7c2d12';
    }

    public function eventDidMount(): string
    {
        return <<<'JS'
            function(info) {
                const main = info.el.querySelector('.fc-event-main');

                if (main) {
                    main.style.fontSize = '0.84rem';
                    main.style.fontWeight = '600';
                    main.style.padding = '5px 7px';
                    main.style.lineHeight = '1.2';
                    main.style.letterSpacing = '0.01em';
                }

                info.el.style.borderRadius = '8px';
                info.el.style.border = 'none';
                info.el.style.boxShadow = 'inset 0 0 0 1px rgba(255, 255, 255, 0.14)';
            }
        JS;
    }
}
