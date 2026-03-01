<?php

namespace App\Filament\Widgets;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\EventDropInfo;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Production Calendar Widget using Guava Calendar.
 *
 * Shows:
 * - Productions: Only Planned and Confirmed (hide when Ongoing/Finished)
 * - Tasks: All tasks with color from task type
 *
 * Default view: Week
 * Drag & Drop: Enabled for both productions and tasks
 */
class ProductionCalendarWidget extends CalendarWidget
{
    protected HtmlString|string|bool|null $heading = 'Calendrier production';

    protected int|string|array $columnSpan = 'full';

    protected CalendarViewType $calendarView = CalendarViewType::TimeGridWeek;

    protected ?string $locale = 'fr';

    /** Enable drag & drop for calendar events */
    protected bool $eventDragEnabled = true;

    /**
     * Get events for the calendar.
     * Returns both productions and tasks that implement Eventable.
     */
    protected function getEvents(FetchInfo $info): Collection|array|Builder
    {
        // Fetch productions (only Planned and Confirmed)
        $productions = Production::query()
            ->whereBetween('production_date', [$info->start, $info->end])
            ->whereIn('status', [ProductionStatus::Planned, ProductionStatus::Confirmed])
            ->get();

        // Fetch all tasks
        $tasks = ProductionTask::query()
            ->with(['production', 'productionTaskType'])
            ->whereBetween('scheduled_date', [$info->start, $info->end])
            ->get();

        // Combine both collections
        return $productions->merge($tasks);
    }

    /**
     * Handle event drop (drag & drop).
     * Updates the date when an event is dragged to a new date.
     */
    protected function onEventDrop(EventDropInfo $info, Model $record): bool
    {
        $newDate = $info->event->getStart();

        // Update based on model type
        if ($record instanceof Production) {
            // Only allow dragging for Planned/Confirmed productions
            if (! in_array($record->status, [ProductionStatus::Planned, ProductionStatus::Confirmed])) {
                return false;
            }

            $record->update(['production_date' => $newDate]);

            return true;
        }

        if ($record instanceof ProductionTask) {
            $record->update(['scheduled_date' => $newDate]);

            return true;
        }

        return false;
    }
}
