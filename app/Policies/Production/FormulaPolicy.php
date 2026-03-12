<?php

declare(strict_types=1);

namespace App\Policies\Production;

use App\Models\Production\Formula;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FormulaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Formula');
    }

    public function view(AuthUser $authUser, Formula $formula): bool
    {
        return $authUser->can('View:Formula');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Formula');
    }

    public function update(AuthUser $authUser, Formula $formula): bool
    {
        return $authUser->can('Update:Formula');
    }

    public function delete(AuthUser $authUser, Formula $formula): bool
    {
        return $authUser->can('Delete:Formula');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Formula');
    }

    public function restore(AuthUser $authUser, Formula $formula): bool
    {
        return $authUser->can('Restore:Formula');
    }

    public function forceDelete(AuthUser $authUser, Formula $formula): bool
    {
        return $authUser->can('ForceDelete:Formula');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Formula');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Formula');
    }

    public function replicate(AuthUser $authUser, Formula $formula): bool
    {
        return $authUser->can('Replicate:Formula');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Formula');
    }
}
