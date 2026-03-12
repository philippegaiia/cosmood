<?php

declare(strict_types=1);

namespace App\Policies\Production;

use App\Models\Production\QcTemplate;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class QcTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:QcTemplate');
    }

    public function view(AuthUser $authUser, QcTemplate $qcTemplate): bool
    {
        return $authUser->can('View:QcTemplate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:QcTemplate');
    }

    public function update(AuthUser $authUser, QcTemplate $qcTemplate): bool
    {
        return $authUser->can('Update:QcTemplate');
    }

    public function delete(AuthUser $authUser, QcTemplate $qcTemplate): bool
    {
        return $authUser->can('Delete:QcTemplate');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:QcTemplate');
    }

    public function restore(AuthUser $authUser, QcTemplate $qcTemplate): bool
    {
        return $authUser->can('Restore:QcTemplate');
    }

    public function forceDelete(AuthUser $authUser, QcTemplate $qcTemplate): bool
    {
        return $authUser->can('ForceDelete:QcTemplate');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:QcTemplate');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:QcTemplate');
    }

    public function replicate(AuthUser $authUser, QcTemplate $qcTemplate): bool
    {
        return $authUser->can('Replicate:QcTemplate');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:QcTemplate');
    }
}
