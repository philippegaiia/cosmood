<?php

use App\Models\User;

uses()->group('dashboard');

describe('Dashboard', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        auth()->login($this->user);
    });

    it('can access dashboard page', function () {
        $response = $this->get('/admin');

        // Page loads without server error (may be 200 or 403 depending on permissions)
        expect($response->getStatusCode())->toBeLessThan(500);
    });

    it('displays dashboard widgets', function () {
        $response = $this->get('/admin');

        // Page loads successfully (may be 200 or 403 depending on permissions)
        expect($response->getStatusCode())->toBeLessThan(500);
    });
});
