<?php

declare(strict_types=1);

namespace App\Policies\Production;

use App\Models\Production\ProductType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProductTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProductType');
    }

    public function view(AuthUser $authUser, ProductType $productType): bool
    {
        return $authUser->can('View:ProductType');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProductType');
    }

    public function update(AuthUser $authUser, ProductType $productType): bool
    {
        return $authUser->can('Update:ProductType');
    }

    public function delete(AuthUser $authUser, ProductType $productType): bool
    {
        return $authUser->can('Delete:ProductType');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ProductType');
    }

    public function restore(AuthUser $authUser, ProductType $productType): bool
    {
        return $authUser->can('Restore:ProductType');
    }

    public function forceDelete(AuthUser $authUser, ProductType $productType): bool
    {
        return $authUser->can('ForceDelete:ProductType');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ProductType');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ProductType');
    }

    public function replicate(AuthUser $authUser, ProductType $productType): bool
    {
        return $authUser->can('Replicate:ProductType');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ProductType');
    }
}
