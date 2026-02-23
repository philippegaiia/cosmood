<?php

use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders the simulateur flash page', function () {
    Livewire::test(\App\Filament\Pages\SimulateurFlash::class)
        ->assertSuccessful()
        ->assertSee('Simulateur Flash');
});
