<?php

declare(strict_types=1);

namespace App\Policies\Supply;

use App\Models\Supply\SupplierListing;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SupplierListingPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SupplierListing');
    }

    public function view(AuthUser $authUser, SupplierListing $supplierListing): bool
    {
        return $authUser->can('View:SupplierListing');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SupplierListing');
    }

    public function update(AuthUser $authUser, SupplierListing $supplierListing): bool
    {
        return $authUser->can('Update:SupplierListing');
    }

    public function delete(AuthUser $authUser, SupplierListing $supplierListing): bool
    {
        return $authUser->can('Delete:SupplierListing');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SupplierListing');
    }

    public function restore(AuthUser $authUser, SupplierListing $supplierListing): bool
    {
        return $authUser->can('Restore:SupplierListing');
    }

    public function forceDelete(AuthUser $authUser, SupplierListing $supplierListing): bool
    {
        return $authUser->can('ForceDelete:SupplierListing');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SupplierListing');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SupplierListing');
    }

    public function replicate(AuthUser $authUser, SupplierListing $supplierListing): bool
    {
        return $authUser->can('Replicate:SupplierListing');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SupplierListing');
    }
}
