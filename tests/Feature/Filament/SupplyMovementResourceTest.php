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
        $response = get(SupplyMovementResource::getUrl('index'));

        expect($response->getStatusCode())->toBe(200);
    });

    it('cannot create supply movements (read-only resource)', function () {
        $response = get(SupplyMovementResource::getUrl('create'));

        expect($response->getStatusCode())->toBe(404);
    });

    it('displays supply movements table', function () {
        $response = get(SupplyMovementResource::getUrl('index'));

        expect($response->getStatusCode())->toBe(200);
        expect($response->getContent())->toContain('Mouvements stock');
    });
});
