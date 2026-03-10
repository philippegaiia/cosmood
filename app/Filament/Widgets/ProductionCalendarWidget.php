<?php

namespace App\Filament\Widgets;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionTask;
use App\Services\Production\TaskGenerationService;
use Carbon\Carbon;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarResource;
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
 * - Resources: Production lines as rows (plus unassigned row)
 *
 * Default view: Resource timeline week
 * Drag & Drop: Enabled for productions (tasks are hidden in this view)
 */
class ProductionCalendarWidget extends CalendarWidget
{
    protected HtmlString|string|bool|null $heading = 'Calendrier production';

    protected int|string|array $columnSpan = 'full';

    protected CalendarViewType $calendarView = CalendarViewType::ResourceTimelineWeek;

    /**
     * Timeline is day-based only for production planning (no hourly slots).
     *
     * @var array<string, mixed>
     */
    protected array $options = [
        'slotDuration' => ['days' => 1],
        'slotLabelInterval' => ['days' => 1],
        'customScrollbars' => true,
        'dayMaxEvents' => false,
        'displayEventEnd' => false,
    ];

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
        return Production::query()
            ->with(['product', 'productionLine'])
            ->whereBetween('production_date', [$info->start, $info->end])
            ->get();
    }

    protected function getResources(): Collection|array|Builder
    {
        $resources = [
            CalendarResource::make(self::UNASSIGNED_RESOURCE_ID)
                ->title(__('Sans ligne')),
        ];

        $lines = ProductionLine::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        foreach ($lines as $line) {
            $resources[] = CalendarResource::make($this->lineToResourceId($line->id))
                ->title($line->is_active ? $line->name : $line->name.' (inactive)')
                ->extendedProps([
                    'lineId' => $line->id,
                    'capacity' => $line->resolveDailyCapacity(),
                    'isActive' => $line->is_active,
                ]);
        }

        return $resources;
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
        $deltaDays = $this->resolveDropDeltaDays($info);

        if ($record instanceof Production) {
            if (! in_array($record->status, [ProductionStatus::Planned, ProductionStatus::Confirmed, ProductionStatus::Ongoing], true)) {
                return false;
            }

            $targetLineId = $this->resourceIdToLineId($info->event->getResourceIds());
            $newDate = Carbon::parse($record->production_date)
                ->startOfDay()
                ->addDays($deltaDays)
                ->toDateString();

            try {
                $record->update([
                    'production_date' => $newDate,
                    'production_line_id' => $targetLineId,
                ]);
            } catch (InvalidArgumentException) {
                return false;
            }

            $this->refreshRecords();

            return true;
        }

        if ($record instanceof ProductionTask) {
            $newDate = Carbon::parse($record->scheduled_date)
                ->startOfDay()
                ->addDays($deltaDays)
                ->toDateString();

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

    private const string UNASSIGNED_RESOURCE_ID = 'line-unassigned';

    private function lineToResourceId(?int $lineId): string
    {
        if (! $lineId) {
            return self::UNASSIGNED_RESOURCE_ID;
        }

        return 'line-'.$lineId;
    }

    /**
     * @param  array<int, int|string>  $resourceIds
     */
    private function resourceIdToLineId(array $resourceIds): ?int
    {
        $resourceId = (string) ($resourceIds[0] ?? self::UNASSIGNED_RESOURCE_ID);

        if ($resourceId === self::UNASSIGNED_RESOURCE_ID) {
            return null;
        }

        if (! str_starts_with($resourceId, 'line-')) {
            return null;
        }

        $lineId = (int) str_replace('line-', '', $resourceId);

        return $lineId > 0 ? $lineId : null;
    }

    private function resolveDropDeltaDays(EventDropInfo $info): int
    {
        $newStart = $info->event->getStart()->copy()->startOfDay();
        $oldStart = $info->oldEvent->getStart()->copy()->startOfDay();

        return $oldStart->diffInDays($newStart, false);
    }
}
