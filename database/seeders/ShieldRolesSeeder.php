<?php

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ShieldRolesSeeder extends Seeder
{
    /**
     * Subjects reserved for Shield administration only.
     *
     * @var array<int, string>
     */
    private const ADMIN_ONLY_SUBJECTS = ['Role', 'User'];

    /**
     * Subjects reserved for manager-level stock intervention.
     *
     * @var array<int, string>
     */
    private const MANAGER_ONLY_SUBJECTS = ['SuppliesMovement'];

    /**
     * View subjects needed by operators for their daily cockpit.
     *
     * @var array<int, string>
     */
    private const OPERATOR_VIEW_SUBJECTS = [
        'HomeDashboard',
        'PilotageStatsWidget',
        'ActiveWavesWidget',
        'PendingOrdersWidget',
        'ReadyToStartProductionsWidget',
        'ProductionsSoonReadyWidget',
        'StockAlertsWidget',
        'TodaysProductionsWidget',
        'TodaysTasksWidget',
        'ProductionDashboard',
        'PlanningBoard',
        'ProductionCalendar',
        'Production',
        'ProductionOutput',
        'ProductionQcCheck',
        'ProductionTask',
        'ProductionWave',
        'Supply',
        'Product',
        'ProductType',
        'QcTemplate',
    ];

    /**
     * Resource update permissions needed by operators to advance live work.
     *
     * @var array<int, string>
     */
    private const OPERATOR_CREATE_SUBJECTS = ['ProductionOutput'];

    /**
     * Resource update permissions needed by operators to advance live work.
     *
     * @var array<int, string>
     */
    private const OPERATOR_UPDATE_SUBJECTS = ['ProductionTask', 'ProductionQcCheck', 'ProductionOutput'];

    /**
     * Resource delete permissions needed by operators to correct execution-only outputs.
     *
     * @var array<int, string>
     */
    private const OPERATOR_DELETE_SUBJECTS = ['ProductionOutput'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->generatePermissionsWhenMissing();

        $permissions = Permission::query()->get();

        $this->syncRole(
            config('filament-shield.super_admin.name', 'super_admin'),
            $permissions
        );

        $this->syncRole(
            'manager',
            $permissions->filter(fn (Permission $permission): bool => $this->isManagerPermission($permission))
        );

        $this->syncRole(
            'planner',
            $permissions->filter(fn (Permission $permission): bool => $this->isPlannerPermission($permission))
        );

        $this->syncRole(
            'operator',
            $permissions->filter(fn (Permission $permission): bool => $this->isOperatorPermission($permission))
        );
    }

    private function generatePermissionsWhenMissing(): void
    {
        if (Permission::query()->exists()) {
            return;
        }

        if (app()->isProduction()) {
            throw new LogicException('Shield permissions must be generated before seeding roles in production.');
        }

        Artisan::call('shield:generate', [
            '--all' => true,
            '--panel' => 'admin',
            '--no-interaction' => true,
        ]);
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     */
    private function syncRole(string $roleName, Collection $permissions): void
    {
        $role = Role::findOrCreate($roleName);

        $role->syncPermissions($permissions);
    }

    private function isOperatorPermission(Permission $permission): bool
    {
        [$affix, $subject] = $this->splitPermissionKey($permission->name);

        if ($affix === 'View') {
            return in_array($subject, self::OPERATOR_VIEW_SUBJECTS, true);
        }

        if ($affix === 'ViewAny') {
            return in_array($subject, self::OPERATOR_CREATE_SUBJECTS, true)
                || in_array($subject, self::OPERATOR_UPDATE_SUBJECTS, true)
                || in_array($subject, self::OPERATOR_DELETE_SUBJECTS, true)
                || in_array($subject, ['Production', 'Supply', 'Product', 'ProductType', 'QcTemplate', 'ProductionWave'], true);
        }

        if ($affix === 'Create') {
            return in_array($subject, self::OPERATOR_CREATE_SUBJECTS, true);
        }

        if ($affix === 'Update') {
            return in_array($subject, self::OPERATOR_UPDATE_SUBJECTS, true);
        }

        if ($affix === 'Delete') {
            return in_array($subject, self::OPERATOR_DELETE_SUBJECTS, true);
        }

        return false;
    }

    private function isPlannerPermission(Permission $permission): bool
    {
        [$affix, $subject] = $this->splitPermissionKey($permission->name);

        if ($this->isForbiddenForOperationalRoles($affix, $subject)) {
            return false;
        }

        if (in_array($subject, self::MANAGER_ONLY_SUBJECTS, true)) {
            return false;
        }

        return ! in_array($affix, ['Delete', 'DeleteAny'], true);
    }

    private function isManagerPermission(Permission $permission): bool
    {
        [$affix, $subject] = $this->splitPermissionKey($permission->name);

        if ($this->isForbiddenForOperationalRoles($affix, $subject)) {
            return false;
        }

        return true;
    }

    private function isForbiddenForOperationalRoles(string $affix, string $subject): bool
    {
        if (in_array($subject, self::ADMIN_ONLY_SUBJECTS, true)) {
            return true;
        }

        return in_array($affix, ['ForceDelete', 'ForceDeleteAny', 'Restore', 'RestoreAny'], true);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPermissionKey(string $permission): array
    {
        $segments = explode(':', $permission, 2);

        return [$segments[0], $segments[1] ?? ''];
    }
}
