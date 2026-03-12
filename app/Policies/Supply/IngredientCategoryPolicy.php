<?php

declare(strict_types=1);

namespace App\Policies\Supply;

use App\Models\Supply\IngredientCategory;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class IngredientCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:IngredientCategory');
    }

    public function view(AuthUser $authUser, IngredientCategory $ingredientCategory): bool
    {
        return $authUser->can('View:IngredientCategory');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:IngredientCategory');
    }

    public function update(AuthUser $authUser, IngredientCategory $ingredientCategory): bool
    {
        return $authUser->can('Update:IngredientCategory');
    }

    public function delete(AuthUser $authUser, IngredientCategory $ingredientCategory): bool
    {
        return $authUser->can('Delete:IngredientCategory');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:IngredientCategory');
    }

    public function restore(AuthUser $authUser, IngredientCategory $ingredientCategory): bool
    {
        return $authUser->can('Restore:IngredientCategory');
    }

    public function forceDelete(AuthUser $authUser, IngredientCategory $ingredientCategory): bool
    {
        return $authUser->can('ForceDelete:IngredientCategory');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:IngredientCategory');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:IngredientCategory');
    }

    public function replicate(AuthUser $authUser, IngredientCategory $ingredientCategory): bool
    {
        return $authUser->can('Replicate:IngredientCategory');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:IngredientCategory');
    }
}
