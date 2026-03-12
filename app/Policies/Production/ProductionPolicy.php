<?php

declare(strict_types=1);

namespace App\Policies\Production;

use App\Models\Production\Production;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProductionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Production');
    }

    public function view(AuthUser $authUser, Production $production): bool
    {
        return $authUser->can('View:Production');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Production');
    }

    public function update(AuthUser $authUser, Production $production): bool
    {
        return $authUser->can('Update:Production');
    }

    public function delete(AuthUser $authUser, Production $production): bool
    {
        return $authUser->can('Delete:Production');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Production');
    }

    public function restore(AuthUser $authUser, Production $production): bool
    {
        return $authUser->can('Restore:Production');
    }

    public function forceDelete(AuthUser $authUser, Production $production): bool
    {
        return $authUser->can('ForceDelete:Production');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Production');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Production');
    }

    public function replicate(AuthUser $authUser, Production $production): bool
    {
        return $authUser->can('Replicate:Production');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Production');
    }
}
