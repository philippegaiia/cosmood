<?php

use App\Livewire\SimulateurFlashPage;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the livewire simulateur flash component', function () {
    Livewire::test(SimulateurFlashPage::class)
        ->assertSuccessful()
        ->assertSee('Simulateur Flash')
        ->assertSee('Synthese');
});
