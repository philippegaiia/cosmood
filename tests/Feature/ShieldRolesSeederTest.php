<?php

use Database\Seeders\ShieldRolesSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('creates the base operational roles', function () {
    Permission::findOrCreate('View:HomeDashboard');
    Permission::findOrCreate('Update:Production');

    $this->seed(ShieldRolesSeeder::class);

    expect(Role::findByName('operator'))->not->toBeNull()
        ->and(Role::findByName('planner'))->not->toBeNull()
        ->and(Role::findByName('manager'))->not->toBeNull()
        ->and(Role::findByName(config('filament-shield.super_admin.name', 'super_admin')))->not->toBeNull();
});

it('syncs all permissions to the super admin role', function () {
    Permission::findOrCreate('View:HomeDashboard');
    Permission::findOrCreate('Delete:Production');

    $this->seed(ShieldRolesSeeder::class);

    $superAdminRole = Role::findByName(config('filament-shield.super_admin.name', 'super_admin'));

    expect($superAdminRole->hasPermissionTo('View:HomeDashboard'))->toBeTrue()
        ->and($superAdminRole->hasPermissionTo('Delete:Production'))->toBeTrue();
});

it('keeps operator permissions narrow', function () {
    Permission::findOrCreate('View:HomeDashboard');
    Permission::findOrCreate('ViewAny:Production');
    Permission::findOrCreate('Update:ProductionTask');
    Permission::findOrCreate('Create:ProductionOutput');
    Permission::findOrCreate('Update:ProductionQcCheck');
    Permission::findOrCreate('Delete:Production');
    Permission::findOrCreate('View:User');

    $this->seed(ShieldRolesSeeder::class);

    $operatorRole = Role::findByName('operator');

    expect($operatorRole->hasPermissionTo('View:HomeDashboard'))->toBeTrue()
        ->and($operatorRole->hasPermissionTo('ViewAny:Production'))->toBeTrue()
        ->and($operatorRole->hasPermissionTo('Update:ProductionTask'))->toBeTrue()
        ->and($operatorRole->hasPermissionTo('Create:ProductionOutput'))->toBeTrue()
        ->and($operatorRole->hasPermissionTo('Update:ProductionQcCheck'))->toBeTrue()
        ->and($operatorRole->hasPermissionTo('Delete:Production'))->toBeFalse()
        ->and($operatorRole->hasPermissionTo('View:User'))->toBeFalse();
});

it('lets planners work without delete or stock-adjustment access', function () {
    Permission::findOrCreate('Update:Production');
    Permission::findOrCreate('Delete:Production');
    Permission::findOrCreate('Create:SuppliesMovement');

    $this->seed(ShieldRolesSeeder::class);

    $plannerRole = Role::findByName('planner');

    expect($plannerRole->hasPermissionTo('Update:Production'))->toBeTrue()
        ->and($plannerRole->hasPermissionTo('Delete:Production'))->toBeFalse()
        ->and($plannerRole->hasPermissionTo('Create:SuppliesMovement'))->toBeFalse();
});

it('lets managers delete production but not manage users or roles', function () {
    Permission::findOrCreate('Delete:Production');
    Permission::findOrCreate('Create:SuppliesMovement');
    Permission::findOrCreate('View:User');
    Permission::findOrCreate('ViewAny:Role');
    Permission::findOrCreate('ForceDelete:Production');

    $this->seed(ShieldRolesSeeder::class);

    $managerRole = Role::findByName('manager');

    expect($managerRole->hasPermissionTo('Delete:Production'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('Create:SuppliesMovement'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('View:User'))->toBeFalse()
        ->and($managerRole->hasPermissionTo('ViewAny:Role'))->toBeFalse()
        ->and($managerRole->hasPermissionTo('ForceDelete:Production'))->toBeFalse();
});

it('syncs delete any permissions only for roles that should bulk delete resources', function () {
    Permission::findOrCreate('DeleteAny:QcTemplate');

    $this->seed(ShieldRolesSeeder::class);

    $operatorRole = Role::findByName('operator');
    $managerRole = Role::findByName('manager');

    expect($operatorRole->hasPermissionTo('DeleteAny:QcTemplate'))->toBeFalse()
        ->and($managerRole->hasPermissionTo('DeleteAny:QcTemplate'))->toBeTrue();
});
