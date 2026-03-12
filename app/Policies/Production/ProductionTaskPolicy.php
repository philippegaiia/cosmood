<?php

declare(strict_types=1);

namespace App\Policies\Production;

use App\Models\Production\ProductionTask;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProductionTaskPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProductionTask');
    }

    public function view(AuthUser $authUser, ProductionTask $productionTask): bool
    {
        return $authUser->can('View:ProductionTask');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProductionTask');
    }

    public function update(AuthUser $authUser, ProductionTask $productionTask): bool
    {
        return $authUser->can('Update:ProductionTask');
    }

    public function delete(AuthUser $authUser, ProductionTask $productionTask): bool
    {
        return $authUser->can('Delete:ProductionTask');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ProductionTask');
    }

    public function restore(AuthUser $authUser, ProductionTask $productionTask): bool
    {
        return $authUser->can('Restore:ProductionTask');
    }

    public function forceDelete(AuthUser $authUser, ProductionTask $productionTask): bool
    {
        return $authUser->can('ForceDelete:ProductionTask');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ProductionTask');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ProductionTask');
    }

    public function replicate(AuthUser $authUser, ProductionTask $productionTask): bool
    {
        return $authUser->can('Replicate:ProductionTask');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ProductionTask');
    }
}
