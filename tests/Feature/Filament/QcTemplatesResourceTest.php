<?php

use App\Filament\Resources\QcTemplates\Pages\ListQcTemplates;
use App\Models\Production\QcTemplate;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->disableAuthorizationBypass();

    Permission::findOrCreate('ViewAny:QcTemplate');
    Permission::findOrCreate('Delete:QcTemplate');
    Permission::findOrCreate('DeleteAny:QcTemplate');

    Role::findOrCreate('operator')->syncPermissions([
        Permission::findByName('ViewAny:QcTemplate'),
    ]);

    Role::findOrCreate('manager')->syncPermissions([
        Permission::findByName('ViewAny:QcTemplate'),
        Permission::findByName('Delete:QcTemplate'),
        Permission::findByName('DeleteAny:QcTemplate'),
    ]);

    $this->user = User::factory()->create();
});

it('can list qc templates in filament resource', function () {
    $this->user->assignRole(Role::findOrCreate('operator'));
    $this->actingAs($this->user);

    $template = QcTemplate::factory()->create();

    Livewire::test(ListQcTemplates::class)
        ->assertCanSeeTableRecords([$template]);
});

it('hides qc template bulk delete from operators', function () {
    $this->user->assignRole(Role::findOrCreate('operator'));
    $this->actingAs($this->user);

    Livewire::test(ListQcTemplates::class)
        ->assertTableBulkActionHidden('delete');
});

it('hides soft deleted qc templates from the list after bulk delete', function () {
    $this->user->assignRole(Role::findOrCreate('manager'));
    $this->actingAs($this->user);

    $template = QcTemplate::factory()->create();

    Livewire::test(ListQcTemplates::class)
        ->callTableBulkAction('delete', [$template])
        ->assertNotified();

    expect($template->fresh()?->trashed())->toBeTrue();

    Livewire::test(ListQcTemplates::class)
        ->assertCanNotSeeTableRecords([$template]);
});
