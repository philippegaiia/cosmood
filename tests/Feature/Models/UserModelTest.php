<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

describe('User Model', function () {
    it('can be created with factory', function () {
        $user = User::factory()->create();

        expect($user)->toBeInstanceOf(User::class)
            ->and($user->email)->not->toBeEmpty();
    });

    it('hashes password cast', function () {
        $user = User::factory()->create(['password' => 'secret-password']);

        expect(Hash::check('secret-password', $user->password))->toBeTrue();
    });

    it('hides sensitive attributes in array', function () {
        $user = User::factory()->create();
        $array = $user->toArray();

        expect($array)->not->toHaveKey('password')
            ->and($array)->not->toHaveKey('remember_token');
    });

    it('can be assigned a role', function () {
        $user = User::factory()->create();
        $role = Role::findOrCreate('manager');

        $user->assignRole($role);

        expect($user->fresh()->hasRole($role))->toBeTrue();
    });

    it('limits stock inventory capabilities to managers', function () {
        $planner = User::factory()->create();
        $planner->assignRole(Role::findOrCreate('planner'));

        $manager = User::factory()->create();
        $manager->assignRole(Role::findOrCreate('manager'));

        expect($planner->canManageSupplyInventory())->toBeFalse()
            ->and($planner->canReceiveSupplierOrdersIntoStock())->toBeFalse()
            ->and($manager->canManageSupplyInventory())->toBeTrue()
            ->and($manager->canReceiveSupplierOrdersIntoStock())->toBeTrue();
    });
});
