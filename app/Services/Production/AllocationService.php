<?php

namespace App\Services\Production;

use App\Enums\RequirementStatus;
use App\Models\Production\ProductionIngredientRequirement;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use Illuminate\Support\Facades\DB;

/**
 * Allocates and deallocates ingredient requirements against stock lots.
 */
class AllocationService
{
    /**
     * Allocates a quantity from one supply lot to a production requirement.
     */
    public function allocate(ProductionIngredientRequirement $requirement, Supply $supply, float $quantity): void
    {
        if ($requirement->isFulfilledByMasterbatch()) {
            throw new \InvalidArgumentException('Cannot allocate requirement fulfilled by masterbatch');
        }

        if ($requirement->isAllocated()) {
            throw new \InvalidArgumentException('Requirement is already allocated');
        }

        if (! $supply->is_in_stock) {
            throw new \InvalidArgumentException('Supply is not in stock');
        }

        DB::transaction(function () use ($requirement, $supply, $quantity): void {
            $newAllocatedQuantity = $requirement->allocated_quantity + $quantity;

            $requirement->update([
                'allocated_quantity' => $newAllocatedQuantity,
                'allocated_from_supply_id' => $supply->id,
                'status' => $newAllocatedQuantity >= $requirement->required_quantity
                    ? RequirementStatus::Allocated
                    : $requirement->status,
            ]);

            $supply->increment('allocated_quantity', $quantity);
        });
    }

    /**
     * Reverts an existing allocation quantity.
     */
    public function deallocate(ProductionIngredientRequirement $requirement, float $quantity): void
    {
        if ($quantity > $requirement->allocated_quantity) {
            throw new \InvalidArgumentException('Cannot deallocate more than allocated');
        }

        $supplyId = $requirement->allocated_from_supply_id;

        DB::transaction(function () use ($requirement, $quantity, $supplyId): void {
            $newAllocatedQuantity = $requirement->allocated_quantity - $quantity;

            $requirement->update([
                'allocated_quantity' => $newAllocatedQuantity,
                'allocated_from_supply_id' => $newAllocatedQuantity > 0 ? $supplyId : null,
                'status' => $newAllocatedQuantity > 0
                    ? RequirementStatus::Allocated
                    : RequirementStatus::Received,
            ]);

            if ($supplyId) {
                Supply::where('id', $supplyId)->decrement('allocated_quantity', $quantity);
            }
        });
    }

    /**
     * Returns available stock lots for one ingredient ordered by expiry date.
     */
    public function getAvailableSupplies(Ingredient $ingredient): \Illuminate\Database\Eloquent\Collection
    {
        return Supply::query()
            ->whereHas('supplierListing', fn ($query) => $query->where('ingredient_id', $ingredient->id))
            ->where('is_in_stock', true)
            ->orderBy('expiry_date')
            ->get();
    }

    /**
     * Checks whether enough available stock exists for one ingredient.
     */
    public function checkAvailability(Ingredient $ingredient, float $quantity): bool
    {
        return $this->getTotalAvailable($ingredient) >= $quantity;
    }

    /**
     * Computes the net available quantity for one ingredient.
     */
    public function getTotalAvailable(Ingredient $ingredient): float
    {
        return Supply::query()
            ->whereHas('supplierListing', fn ($query) => $query->where('ingredient_id', $ingredient->id))
            ->where('is_in_stock', true)
            ->selectRaw('SUM(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0) - COALESCE(allocated_quantity, 0)) as total')
            ->value('total') ?? 0;
    }
}
