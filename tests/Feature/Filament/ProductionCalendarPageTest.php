<?php

use App\Filament\Widgets\ProductionCalendarWidget;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\User;
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

it('returns task events for the visible range', function () {
    $production = Production::factory()->create([
        'batch_number' => 'B-CAL-001',
        'production_date' => Carbon::today(),
    ]);

    ProductionTask::factory()->create([
        'production_id' => $production->id,
        'name' => 'Melange cuve',
        'scheduled_date' => Carbon::today(),
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
            $event instanceof ProductionTask => $event->name,
            default => null,
        })
        ->filter()
        ->join('|');

    expect($labels)->toContain('Melange cuve');
});
