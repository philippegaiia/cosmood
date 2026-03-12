<?php

use App\Filament\Resources\Supply\StockMovements\StockMovementResource;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses()->group('supply');

describe('Stock Movement Resource', function () {
    beforeEach(function () {
        $this->disableAuthorizationBypass();

        Permission::findOrCreate('ViewAny:SuppliesMovement');

        Role::findOrCreate('manager')->syncPermissions([
            Permission::findByName('ViewAny:SuppliesMovement'),
        ]);

        Role::findOrCreate('operator')->syncPermissions([]);
    });

    it('allows managers to access stock movements index page', function () {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('manager'));
        auth()->login($user);

        $response = $this->get(StockMovementResource::getUrl('index'));

        expect($response->getStatusCode())->toBeLessThan(500);
    });

    it('redirects operators away from forbidden stock movements access', function () {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('operator'));
        auth()->login($user);

        $response = $this->get(StockMovementResource::getUrl('index'));

        $response->assertRedirect(Filament::getHomeUrl());
    });
});
