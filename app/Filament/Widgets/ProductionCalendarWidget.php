<?php

namespace App\Filament\Widgets;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Services\Production\TaskGenerationService;
use Carbon\Carbon;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\EventDropInfo;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * Production Calendar Widget using Guava Calendar.
 *
 * Shows:
 * - Productions: All statuses with status-based colors
 * - Tasks: All tasks with color from task type
 *
 * Default view: Month (all-day, no hourly slots)
 * Drag & Drop: Enabled for both productions and tasks
 */
class ProductionCalendarWidget extends CalendarWidget
{
    protected HtmlString|string|bool|null $heading = 'Calendrier production';

    protected int|string|array $columnSpan = 'full';

    protected CalendarViewType $calendarView = CalendarViewType::DayGridMonth;

    protected ?string $locale = 'fr';

    /** Enable drag & drop for calendar events */
    protected bool $eventDragEnabled = true;

    /** Enable click handling so event URLs are opened. */
    protected bool $eventClickEnabled = true;

    /**
     * Get events for the calendar.
     * Returns both productions and tasks that implement Eventable.
     */
    protected function getEvents(FetchInfo $info): Collection|array|Builder
    {
        $productions = Production::query()
            ->with('product')
            ->whereBetween('production_date', [$info->start, $info->end])
            ->get();

        $tasks = ProductionTask::query()
            ->with(['production.product', 'productionTaskType'])
            ->whereBetween('scheduled_date', [$info->start, $info->end])
            ->get();

        return $productions->merge($tasks);
    }

    protected function eventContent(): string
    {
        return view('filament.widgets.production-calendar.event')->render();
    }

    /**
     * Handle event drop (drag & drop).
     * Updates the date when an event is dragged to a new date.
     */
    protected function onEventDrop(EventDropInfo $info, Model $record): bool
    {
        $newDate = Carbon::parse($info->event->getStart())->toDateString();

        if ($record instanceof Production) {
            if (! in_array($record->status, [ProductionStatus::Planned, ProductionStatus::Confirmed, ProductionStatus::Ongoing], true)) {
                return false;
            }

            try {
                $record->update(['production_date' => $newDate]);
            } catch (InvalidArgumentException) {
                return false;
            }

            $this->refreshRecords();

            return true;
        }

        if ($record instanceof ProductionTask) {
            try {
                app(TaskGenerationService::class)->setManualSchedule($record, $newDate);
            } catch (InvalidArgumentException) {
                return false;
            }

            $this->refreshRecords();

            return true;
        }

        return false;
    }
}
