<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists users in table', function () {
    $users = User::factory()->count(3)->create();

    Livewire::test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)
        ->assertCanSeeTableRecords($users);
});

it('searches users by name', function () {
    $userA = User::factory()->create(['name' => 'Alice Dupont']);
    $userB = User::factory()->create(['name' => 'Bernard Martin']);

    Livewire::test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)
        ->searchTable('Alice')
        ->assertCanSeeTableRecords([$userA])
        ->assertCanNotSeeTableRecords([$userB]);
});
