<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

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
});
