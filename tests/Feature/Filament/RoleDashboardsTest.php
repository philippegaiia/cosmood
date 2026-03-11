<?php

use App\Filament\Pages\ProductionDashboard;
use App\Filament\Pages\PurchasingDashboard;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);
});

it('renders the production dashboard page', function (): void {
    Livewire::test(ProductionDashboard::class)
        ->assertSuccessful();

    $response = $this->get('/admin/production-dashboard');

    expect($response->getStatusCode())->toBeLessThan(500);
});

it('renders the purchasing dashboard page', function (): void {
    Livewire::test(PurchasingDashboard::class)
        ->assertSuccessful();

    $response = $this->get('/admin/purchasing-dashboard');

    expect($response->getStatusCode())->toBeLessThan(500);
});
