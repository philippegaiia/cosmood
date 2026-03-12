<?php

use App\Enums\ProductionStatus;
use App\Filament\Pages\ProductionCalendar;
use App\Filament\Widgets\ProductionCalendarWidget;
use App\Models\Production\Product;
use App\Models\Production\ProductCategory;
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
    Livewire::test(ProductionCalendar::class)
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

    expect($events)->toHaveCount(1)
        ->and($events->first()->getExtendedProps()['lotLabel'])->toBe($production->toCalendarEvent()->getExtendedProps()['lotLabel']);
});

it('filters productions by ready date basis', function () {
    $readyProduction = Production::factory()->create([
        'production_date' => '2026-03-01',
        'ready_date' => '2026-03-15',
    ]);

    Production::factory()->create([
        'production_date' => '2026-03-20',
        'ready_date' => '2026-04-10',
    ]);

    $widget = new class extends ProductionCalendarWidget
    {
        public function fetchEventsForTest(FetchInfo $info): iterable
        {
            return $this->getEvents($info);
        }
    };

    $widget->dateBasis = 'ready_date';

    $events = collect($widget->fetchEventsForTest(new FetchInfo([
        'startStr' => '2026-03-01',
        'endStr' => '2026-03-31',
    ])));

    expect($events)->toHaveCount(1)
        ->and($events->first()->getExtendedProps()['url'])->toContain('/productions/'.$readyProduction->id);
});

it('filters productions by status and line', function () {
    $line = ProductionLine::factory()->create();

    $matchingProduction = Production::factory()->confirmed()->create([
        'production_line_id' => $line->id,
        'production_date' => Carbon::today(),
    ]);

    Production::factory()->planned()->create([
        'production_line_id' => $line->id,
        'production_date' => Carbon::today(),
    ]);

    Production::factory()->confirmed()->create([
        'production_date' => Carbon::today(),
    ]);

    $widget = new class extends ProductionCalendarWidget
    {
        public function fetchEventsForTest(FetchInfo $info): iterable
        {
            return $this->getEvents($info);
        }
    };

    $widget->statusFilter = ProductionStatus::Confirmed->value;
    $widget->lineFilter = $line->id;

    $events = collect($widget->fetchEventsForTest(new FetchInfo([
        'startStr' => Carbon::today()->startOfMonth()->toDateString(),
        'endStr' => Carbon::today()->endOfMonth()->toDateString(),
    ])));

    expect($events)->toHaveCount(1)
        ->and($events->first()->getExtendedProps()['url'])->toContain('/productions/'.$matchingProduction->id);
});

it('filters productions by product category', function () {
    $matchingCategory = ProductCategory::factory()->create([
        'name' => 'Savons',
    ]);
    $otherCategory = ProductCategory::factory()->create([
        'name' => 'Baumes',
    ]);

    $matchingProduct = Product::factory()->create([
        'product_category_id' => $matchingCategory->id,
    ]);
    $otherProduct = Product::factory()->create([
        'product_category_id' => $otherCategory->id,
    ]);

    $matchingProduction = Production::factory()->create([
        'product_id' => $matchingProduct->id,
        'production_date' => Carbon::today(),
    ]);

    Production::factory()->create([
        'product_id' => $otherProduct->id,
        'production_date' => Carbon::today(),
    ]);

    $widget = new class extends ProductionCalendarWidget
    {
        public function fetchEventsForTest(FetchInfo $info): iterable
        {
            return $this->getEvents($info);
        }
    };

    $widget->productCategoryFilter = $matchingCategory->id;

    $events = collect($widget->fetchEventsForTest(new FetchInfo([
        'startStr' => Carbon::today()->startOfMonth()->toDateString(),
        'endStr' => Carbon::today()->endOfMonth()->toDateString(),
    ])));

    expect($events)->toHaveCount(1)
        ->and($events->first()->getExtendedProps()['url'])->toContain('/productions/'.$matchingProduction->id);
});

it('uses month view, read only mode and click navigation', function () {
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

        public function eventDragEnabledForTest(): bool
        {
            return $this->eventDragEnabled;
        }

        public function optionsForTest(): array
        {
            return $this->options;
        }
    };

    expect($widget->viewForTest())->toBe(CalendarViewType::DayGridMonth)
        ->and($widget->eventClickEnabledForTest())->toBeTrue()
        ->and($widget->eventDragEnabledForTest())->toBeFalse()
        ->and($widget->optionsForTest()['headerToolbar']['end'])->toBe('dayGridMonth,timeGridWeek,listMonth');
});
