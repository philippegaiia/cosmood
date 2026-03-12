<?php

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists users in table', function () {
    $users = User::factory()->count(3)->create();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords($users);
});

it('searches users by name', function () {
    $userA = User::factory()->create(['name' => 'Alice Dupont']);
    $userB = User::factory()->create(['name' => 'Bernard Martin']);

    Livewire::test(ListUsers::class)
        ->searchTable('Alice')
        ->assertCanSeeTableRecords([$userA])
        ->assertCanNotSeeTableRecords([$userB]);
});

it('assigns roles when creating a user', function () {
    $role = Role::findOrCreate('manager');

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Manager Test',
            'email' => 'manager@example.com',
            'password' => 'password',
            'roles' => [$role->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $user = User::query()->where('email', 'manager@example.com')->firstOrFail();

    expect($user->hasRole($role))->toBeTrue();
});
