<?php

use App\Models\Supply\SuppliesMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('SuppliesMovement Model', function () {
    it('can be created', function () {
        $movement = SuppliesMovement::create();

        expect($movement)->toBeInstanceOf(SuppliesMovement::class)
            ->and($movement->id)->not->toBeNull();
    });
});
