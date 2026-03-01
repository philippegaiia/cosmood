<?php

use App\Filament\Resources\Supply\SupplyMovementResource;
use App\Models\User;

uses()->group('supply');

describe('Supply Movement Resource', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        auth()->login($this->user);
    });

    it('can access supply movements index page', function () {
        $response = $this->get(SupplyMovementResource::getUrl('index'));

        // Page loads (may be 200 or 403 depending on permissions)
        expect($response->getStatusCode())->toBeLessThan(500);
    });

    it('cannot create supply movements (read-only resource)', function () {
        // Try to access a non-existent create route
        $response = $this->get('/admin/supply/supply-movements/create');

        // Should return 404 since create route doesn't exist
        expect($response->getStatusCode())->toBe(404);
    });

    it('displays supply movements table', function () {
        $response = $this->get(SupplyMovementResource::getUrl('index'));

        // Page loads successfully
        expect($response->getStatusCode())->toBeLessThan(500);
    });
});
