<?php

use App\Models\Production\QcTemplate;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('can list qc templates in filament resource', function () {
    $template = QcTemplate::factory()->create();

    Livewire::test(\App\Filament\Resources\QcTemplates\Pages\ListQcTemplates::class)
        ->assertCanSeeTableRecords([$template]);
});
