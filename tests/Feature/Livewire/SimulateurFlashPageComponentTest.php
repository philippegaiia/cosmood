<?php

use App\Livewire\SimulateurFlashPage;
use App\Models\Production\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the livewire simulateur flash component', function () {
    Livewire::test(SimulateurFlashPage::class)
        ->assertSuccessful()
        ->assertSee('Simulateur Flash')
        ->assertSee('Rechercher')
        ->assertSee('Synthese')
        ->assertSee('Print');
});

it('shows products in selection even when they are inactive in legacy data', function () {
    Product::factory()->create([
        'name' => 'Savon Legacy Inactif',
        'is_active' => 0,
    ]);

    Livewire::test(SimulateurFlashPage::class)
        ->assertSee('Savon Legacy Inactif');
});
