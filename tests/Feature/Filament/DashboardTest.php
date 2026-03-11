<?php

use App\Enums\OrderStatus;
use App\Filament\Pages\HomeDashboard;
use App\Models\Supply\SupplierOrder;
use App\Models\User;
use Livewire\Livewire;

uses()->group('dashboard');

describe('Dashboard', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        auth()->login($this->user);
    });

    it('can access dashboard page', function () {
        $response = $this->get('/admin');

        expect($response->getStatusCode())->toBeLessThan(500);
    });

    it('displays dashboard widgets', function () {
        Livewire::test(HomeDashboard::class)
            ->assertSuccessful();
    });

    it('loads the dashboard when pending supplier orders exist', function () {
        SupplierOrder::factory()->create([
            'order_status' => OrderStatus::Passed,
        ]);

        $response = $this->get('/admin');

        expect($response->getStatusCode())->toBeLessThan(500);
    });
});
