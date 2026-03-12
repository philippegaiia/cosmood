<?php

declare(strict_types=1);

namespace App\Policies\Production;

use App\Models\Production\ProductionWave;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProductionWavePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProductionWave');
    }

    public function view(AuthUser $authUser, ProductionWave $productionWave): bool
    {
        return $authUser->can('View:ProductionWave');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProductionWave');
    }

    public function update(AuthUser $authUser, ProductionWave $productionWave): bool
    {
        return $authUser->can('Update:ProductionWave');
    }

    public function delete(AuthUser $authUser, ProductionWave $productionWave): bool
    {
        return $authUser->can('Delete:ProductionWave');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ProductionWave');
    }

    public function restore(AuthUser $authUser, ProductionWave $productionWave): bool
    {
        return $authUser->can('Restore:ProductionWave');
    }

    public function forceDelete(AuthUser $authUser, ProductionWave $productionWave): bool
    {
        return $authUser->can('ForceDelete:ProductionWave');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ProductionWave');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ProductionWave');
    }

    public function replicate(AuthUser $authUser, ProductionWave $productionWave): bool
    {
        return $authUser->can('Replicate:ProductionWave');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ProductionWave');
    }
}
