<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\Supply;
use App\Services\InventoryMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates internal stock lots from finished productions that output manufactured ingredients.
 */
class ManufacturedIngredientStockService
{
    private const INTERNAL_SUPPLIER_CODE = 'GAIIA-INT';

    private const INTERNAL_SUPPLIER_NAME = 'GAIIA (Interne)';

    public function __construct(
        private readonly InventoryMovementService $inventoryMovementService,
    ) {}

    /**
     * Ensures one output supply lot exists for a finished production.
     */
    public function ensureStockFromFinishedProduction(Production $production): ?Supply
    {
        if ($production->status !== ProductionStatus::Finished) {
            return null;
        }

        return DB::transaction(function () use ($production): ?Supply {
            /** @var Production $lockedProduction */
            $lockedProduction = Production::query()
                ->lockForUpdate()
                ->with(['producedIngredient', 'product.producedIngredient'])
                ->findOrFail($production->id);

            if ($lockedProduction->status !== ProductionStatus::Finished) {
                return null;
            }

            $ingredient = $this->resolveProducedIngredient($lockedProduction);

            if (! $ingredient) {
                return null;
            }

            $quantityKg = (float) ($lockedProduction->planned_quantity ?? 0);

            if ($quantityKg <= 0) {
                return null;
            }

            $existingSupply = Supply::query()
                ->where('source_production_id', $lockedProduction->id)
                ->first();

            if ($existingSupply) {
                return $existingSupply;
            }

            $listing = $this->resolveInternalListing($ingredient);
            $lotIdentifier = $lockedProduction->getLotIdentifier();

            $supply = Supply::query()->create([
                'source_production_id' => $lockedProduction->id,
                'supplier_order_item_id' => null,
                'supplier_listing_id' => $listing->id,
                'order_ref' => 'INT-'.$lotIdentifier,
                'batch_number' => $lotIdentifier,
                'initial_quantity' => $quantityKg,
                'quantity_in' => $quantityKg,
                'quantity_out' => 0,
                'allocated_quantity' => 0,
                'unit_price' => $ingredient->price,
                'expiry_date' => null,
                'delivery_date' => $lockedProduction->production_date,
                'is_in_stock' => true,
            ]);

            $this->inventoryMovementService->recordInboundFromProduction(
                supply: $supply,
                production: $lockedProduction,
                quantityKg: $quantityKg,
                reason: 'Manufactured ingredient produced',
            );

            return $supply;
        }, attempts: 5);
    }

    /**
     * Resolves the produced ingredient from production or product defaults.
     */
    private function resolveProducedIngredient(Production $production): ?Ingredient
    {
        if ($production->producedIngredient) {
            return $production->producedIngredient;
        }

        $productIngredientId = $production->product?->produced_ingredient_id;

        if (! $productIngredientId) {
            return null;
        }

        $ingredient = Ingredient::query()->find($productIngredientId);

        if (! $ingredient) {
            return null;
        }

        $production->updateQuietly([
            'produced_ingredient_id' => $ingredient->id,
        ]);

        return $ingredient;
    }

    /**
     * Finds or creates the internal supplier listing used for manufactured outputs.
     */
    private function resolveInternalListing(Ingredient $ingredient): SupplierListing
    {
        $supplier = Supplier::query()
            ->withTrashed()
            ->where('code', self::INTERNAL_SUPPLIER_CODE)
            ->first();

        if (! $supplier) {
            $supplier = Supplier::query()->create([
                'name' => self::INTERNAL_SUPPLIER_NAME,
                'code' => self::INTERNAL_SUPPLIER_CODE,
                'slug' => Str::slug(self::INTERNAL_SUPPLIER_NAME),
                'is_active' => true,
            ]);
        }

        if ($supplier->trashed()) {
            $supplier->restore();
        }

        $listing = SupplierListing::query()
            ->withTrashed()
            ->where('supplier_id', $supplier->id)
            ->where('ingredient_id', $ingredient->id)
            ->first();

        if (! $listing) {
            $listing = SupplierListing::query()->create([
                'supplier_id' => $supplier->id,
                'ingredient_id' => $ingredient->id,
                'name' => $ingredient->name,
                'code' => 'INT-ING-'.$ingredient->id,
                'supplier_code' => 'INT-'.$ingredient->code,
                'unit_of_measure' => 'kg',
                'unit_weight' => 1,
                'price' => $ingredient->price,
                'is_active' => true,
                'organic' => true,
                'fairtrade' => false,
                'cosmos' => false,
                'ecocert' => false,
            ]);
        }

        if ($listing->trashed()) {
            $listing->restore();
        }

        if ($ingredient->price !== null && (float) $listing->price !== (float) $ingredient->price) {
            $listing->update([
                'price' => $ingredient->price,
            ]);
        }

        return $listing;
    }
}
