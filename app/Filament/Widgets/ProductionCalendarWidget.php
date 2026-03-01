<?php

namespace App\Filament\Widgets;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Production Calendar Widget using Guava Calendar.
 *
 * Shows:
 * - Productions: Only Planned and Confirmed (hide when Ongoing/Finished)
 * - Tasks: All tasks with color from task type
 *
 * Default view: Week
 */
class ProductionCalendarWidget extends CalendarWidget
{
    protected static ?string $heading = 'Calendrier production';

    protected int|string|array $columnSpan = 'full';

    protected CalendarViewType $calendarView = CalendarViewType::DayGridWeek;

    protected ?string $locale = 'fr';

    protected function getEvents(FetchInfo $info): Collection|array|Builder
    {
        $productionEvents = $this->getProductionEvents($info);
        $taskEvents = $this->getTaskEvents($info);

        return $productionEvents->merge($taskEvents);
    }

    /**
     * Get production events (only Planned and Confirmed).
     */
    private function getProductionEvents(FetchInfo $info): Collection
    {
        return Production::query()
            ->with('product:id,name')
            ->whereBetween('production_date', [$info->start, $info->end])
            ->whereIn('status', [ProductionStatus::Planned, ProductionStatus::Confirmed])
            ->get()
            ->map(function (Production $production): CalendarEvent {
                $title = $production->product?->name ?? 'Sans nom';

                return CalendarEvent::make($production)
                    ->title($title)
                    ->start($production->production_date)
                    ->end($production->production_date)
                    ->allDay()
                    ->backgroundColor($this->getProductionColor($production))
                    ->textColor('#ffffff')
                    ->action('edit');
            });
    }

    /**
     * Get task events (all tasks).
     */
    private function getTaskEvents(FetchInfo $info): Collection
    {
        return ProductionTask::query()
            ->with(['production:id,batch_number,permanent_batch_number,status', 'productionTaskType:id,color'])
            ->whereBetween('scheduled_date', [$info->start, $info->end])
            ->get()
            ->map(function (ProductionTask $task): CalendarEvent {
                $title = $task->name ?? 'Tâche';
                $backgroundColor = $task->productionTaskType?->color ?? '#6366f1';

                // If parent production is ongoing, make it visually distinct
                if ($task->production?->status === ProductionStatus::Ongoing) {
                    $backgroundColor = $this->adjustColorForOngoing($backgroundColor);
                }

                return CalendarEvent::make($task)
                    ->title($title)
                    ->start($task->scheduled_date)
                    ->end($task->scheduled_date)
                    ->allDay()
                    ->backgroundColor($backgroundColor)
                    ->textColor('#ffffff')
                    ->action('edit');
            });
    }

    /**
     * Get color for production based on status.
     */
    private function getProductionColor(Production $production): string
    {
        return match ($production->status) {
            ProductionStatus::Planned => '#64748b', // slate-500
            ProductionStatus::Confirmed => '#3b82f6', // blue-500
            default => '#6b7280',
        };
    }

    /**
     * Adjust color for ongoing production tasks (make slightly lighter).
     */
    private function adjustColorForOngoing(string $color): string
    {
        // Simple approach: blend with white to make it lighter
        // This is a visual indicator that the parent production has started
        $r = hexdec(substr($color, 1, 2));
        $g = hexdec(substr($color, 3, 2));
        $b = hexdec(substr($color, 5, 2));

        // Blend with white (80% original, 20% white)
        $r = intval($r * 0.8 + 255 * 0.2);
        $g = intval($g * 0.8 + 255 * 0.2);
        $b = intval($b * 0.8 + 255 * 0.2);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    public function editAction(): \Guava\Calendar\Filament\Actions\EditAction
    {
        return \Guava\Calendar\Filament\Actions\EditAction::make('edit');
    }
}
