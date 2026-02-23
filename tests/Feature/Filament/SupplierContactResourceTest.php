<?php

use App\Models\Supply\SupplierContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists supplier contacts in table', function () {
    $contacts = SupplierContact::factory()->count(3)->create();

    Livewire::test(\App\Filament\Resources\Supply\SupplierContactResource\Pages\ListSupplierContacts::class)
        ->assertCanSeeTableRecords($contacts);
});

it('searches supplier contacts by first name', function () {
    $contactA = SupplierContact::factory()->create(['first_name' => 'Alice']);
    $contactB = SupplierContact::factory()->create(['first_name' => 'Bernard']);

    Livewire::test(\App\Filament\Resources\Supply\SupplierContactResource\Pages\ListSupplierContacts::class)
        ->searchTable('Alice')
        ->assertCanSeeTableRecords([$contactA])
        ->assertCanNotSeeTableRecords([$contactB]);
});
