<?php

declare(strict_types=1);

namespace App\Policies\Production;

use App\Models\Production\ProductionTaskType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProductionTaskTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProductionTaskType');
    }

    public function view(AuthUser $authUser, ProductionTaskType $productionTaskType): bool
    {
        return $authUser->can('View:ProductionTaskType');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProductionTaskType');
    }

    public function update(AuthUser $authUser, ProductionTaskType $productionTaskType): bool
    {
        return $authUser->can('Update:ProductionTaskType');
    }

    public function delete(AuthUser $authUser, ProductionTaskType $productionTaskType): bool
    {
        return $authUser->can('Delete:ProductionTaskType');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ProductionTaskType');
    }

    public function restore(AuthUser $authUser, ProductionTaskType $productionTaskType): bool
    {
        return $authUser->can('Restore:ProductionTaskType');
    }

    public function forceDelete(AuthUser $authUser, ProductionTaskType $productionTaskType): bool
    {
        return $authUser->can('ForceDelete:ProductionTaskType');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ProductionTaskType');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ProductionTaskType');
    }

    public function replicate(AuthUser $authUser, ProductionTaskType $productionTaskType): bool
    {
        return $authUser->can('Replicate:ProductionTaskType');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ProductionTaskType');
    }
}
