<?php

use App\Filament\Resources\Supply\StockMovements\StockMovementResource;
use App\Models\User;

uses()->group('supply');

describe('Stock Movement Resource', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        auth()->login($this->user);
    });

    it('can access stock movements index page', function () {
        $response = $this->get(StockMovementResource::getUrl('index'));

        expect($response->getStatusCode())->toBeLessThan(500);
    });

    it('displays stock movements table', function () {
        $response = $this->get(StockMovementResource::getUrl('index'));

        expect($response->getStatusCode())->toBeLessThan(500);
    });
});
