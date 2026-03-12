<?php

declare(strict_types=1);

namespace App\Policies\Supply;

use App\Models\Supply\SupplierOrder;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SupplierOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SupplierOrder');
    }

    public function view(AuthUser $authUser, SupplierOrder $supplierOrder): bool
    {
        return $authUser->can('View:SupplierOrder');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SupplierOrder');
    }

    public function update(AuthUser $authUser, SupplierOrder $supplierOrder): bool
    {
        return $authUser->can('Update:SupplierOrder');
    }

    public function delete(AuthUser $authUser, SupplierOrder $supplierOrder): bool
    {
        return $authUser->can('Delete:SupplierOrder');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SupplierOrder');
    }

    public function restore(AuthUser $authUser, SupplierOrder $supplierOrder): bool
    {
        return $authUser->can('Restore:SupplierOrder');
    }

    public function forceDelete(AuthUser $authUser, SupplierOrder $supplierOrder): bool
    {
        return $authUser->can('ForceDelete:SupplierOrder');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SupplierOrder');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SupplierOrder');
    }

    public function replicate(AuthUser $authUser, SupplierOrder $supplierOrder): bool
    {
        return $authUser->can('Replicate:SupplierOrder');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SupplierOrder');
    }
}
