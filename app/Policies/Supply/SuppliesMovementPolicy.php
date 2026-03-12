<?php

declare(strict_types=1);

namespace App\Policies\Supply;

use App\Models\Supply\SuppliesMovement;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SuppliesMovementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SuppliesMovement');
    }

    public function view(AuthUser $authUser, SuppliesMovement $suppliesMovement): bool
    {
        return $authUser->can('View:SuppliesMovement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SuppliesMovement');
    }

    public function update(AuthUser $authUser, SuppliesMovement $suppliesMovement): bool
    {
        return $authUser->can('Update:SuppliesMovement');
    }

    public function delete(AuthUser $authUser, SuppliesMovement $suppliesMovement): bool
    {
        return $authUser->can('Delete:SuppliesMovement');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SuppliesMovement');
    }

    public function restore(AuthUser $authUser, SuppliesMovement $suppliesMovement): bool
    {
        return $authUser->can('Restore:SuppliesMovement');
    }

    public function forceDelete(AuthUser $authUser, SuppliesMovement $suppliesMovement): bool
    {
        return $authUser->can('ForceDelete:SuppliesMovement');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SuppliesMovement');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SuppliesMovement');
    }

    public function replicate(AuthUser $authUser, SuppliesMovement $suppliesMovement): bool
    {
        return $authUser->can('Replicate:SuppliesMovement');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SuppliesMovement');
    }
}
