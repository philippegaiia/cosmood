<?php

use App\Filament\Widgets\ProductionCalendarWidget;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\User;
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

it('returns production and task events for the visible range', function () {
    $production = Production::factory()->create([
        'batch_number' => 'B-CAL-001',
        'production_date' => Carbon::today(),
    ]);

    ProductionTask::factory()->create([
        'production_id' => $production->id,
        'name' => 'Melange cuve',
        'scheduled_date' => Carbon::today(),
    ]);

    $events = collect(app(ProductionCalendarWidget::class)->fetchEvents([
        'start' => Carbon::today()->startOfMonth()->toDateString(),
        'end' => Carbon::today()->endOfMonth()->toDateString(),
    ]));

    expect($events->pluck('title')->join('|'))
        ->toContain('B-CAL-001')
        ->toContain('Melange cuve');
});
