<?php

use App\Models\User;

uses()->group('dashboard');

describe('Dashboard', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        auth()->login($this->user);
    });

    it('can access dashboard page', function () {
        $response = get('/admin');

        expect($response->getStatusCode())->toBe(200);
    });

    it('displays dashboard widgets', function () {
        $response = get('/admin');

        expect($response->getStatusCode())->toBe(200);
        expect($response->getContent())->toContain('Planification du jour');
        expect($response->getContent())->toContain('Prêts à lancer');
        expect($response->getContent())->toContain('Alertes stock');
    });
});
