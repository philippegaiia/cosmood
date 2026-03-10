<?php

use App\Enums\ProductionStatus;
use App\Filament\Widgets\ProductionCalendarWidget;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use App\Models\User;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders production calendar page', function () {
    Livewire::test(\App\Filament\Pages\ProductionCalendar::class)
        ->assertSuccessful();
});

it('returns production events for the visible range', function () {
    $production = Production::factory()->create([
        'batch_number' => 'B-CAL-001',
        'production_date' => Carbon::today(),
    ]);

    $widget = new class extends ProductionCalendarWidget
    {
        public function fetchEventsForTest(FetchInfo $info): iterable
        {
            return $this->getEvents($info);
        }
    };

    $events = collect($widget->fetchEventsForTest(new FetchInfo([
        'startStr' => Carbon::today()->startOfMonth()->toDateString(),
        'endStr' => Carbon::today()->endOfMonth()->toDateString(),
    ])));

    $labels = $events
        ->map(fn ($event): ?string => match (true) {
            $event instanceof Production => $event->batch_number,
            default => null,
        })
        ->filter()
        ->join('|');

    expect($labels)->toContain($production->batch_number);
});

it('returns productions for all statuses in the visible range', function () {
    $cancelledProduction = Production::factory()->cancelled()->create([
        'batch_number' => 'B-CAL-CAN',
        'production_date' => Carbon::today(),
    ]);

    $widget = new class extends ProductionCalendarWidget
    {
        public function fetchEventsForTest(FetchInfo $info): iterable
        {
            return $this->getEvents($info);
        }
    };

    $events = collect($widget->fetchEventsForTest(new FetchInfo([
        'startStr' => Carbon::today()->startOfMonth()->toDateString(),
        'endStr' => Carbon::today()->endOfMonth()->toDateString(),
    ])));

    $cancelledProductionEvent = $events
        ->whereInstanceOf(Production::class)
        ->first(fn (Production $eventProduction): bool => $eventProduction->id === $cancelledProduction->id);

    expect($cancelledProductionEvent)->not->toBeNull()
        ->and($cancelledProductionEvent->status)->toBe(ProductionStatus::Cancelled);
});

it('uses resource timeline view and enables event clicks', function () {
    $widget = new class extends ProductionCalendarWidget
    {
        public function viewForTest(): CalendarViewType
        {
            return $this->calendarView;
        }

        public function eventClickEnabledForTest(): bool
        {
            return $this->eventClickEnabled;
        }
    };

    expect($widget->viewForTest())->toBe(CalendarViewType::ResourceTimelineWeek)
        ->and($widget->eventClickEnabledForTest())->toBeTrue();
});

it('includes production lines as calendar resources', function () {
    $line = ProductionLine::factory()->create([
        'name' => 'Ligne A',
    ]);

    $widget = new class extends ProductionCalendarWidget
    {
        public function resourcesForTest(): array
        {
            return $this->getResourcesJs();
        }
    };

    $resources = collect($widget->resourcesForTest());

    expect($resources->pluck('id')->all())
        ->toContain('line-unassigned')
        ->toContain('line-'.$line->id);
});
