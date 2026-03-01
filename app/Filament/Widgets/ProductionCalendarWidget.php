<?php

namespace App\Filament\Widgets;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;
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
 */
class ProductionCalendarWidget extends CalendarWidget
{
    protected HtmlString|string|bool|null $heading = 'Calendrier production';

    protected int|string|array $columnSpan = 'full';

    protected CalendarViewType $calendarView = CalendarViewType::TimeGridWeek;

    protected ?string $locale = 'fr';

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
}
