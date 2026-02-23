<?php

namespace App\Services;

use App\Models\Production\Production;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\SuppliesMovement;
use App\Models\Supply\Supply;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryMovementService
{
    public function receiveOrderItemIntoStock(SupplierOrderItem $item, string $orderRef, string $deliveryDate, ?User $user = null): Supply
    {
        return DB::transaction(function () use ($item, $orderRef, $deliveryDate, $user): Supply {
            /** @var SupplierOrderItem $lockedItem */
            $lockedItem = SupplierOrderItem::query()
                ->lockForUpdate()
                ->findOrFail($item->id);

            if ($lockedItem->isInSupplies() || $lockedItem->supply()->exists()) {
                throw new \RuntimeException('Supplier order item is already in stock.');
            }

            $totalQuantity = (float) ($lockedItem->quantity ?? 0) * (float) ($lockedItem->unit_weight ?? 0);

            if ($totalQuantity <= 0) {
                throw new \InvalidArgumentException('Supplier order item quantity must be greater than zero.');
            }

            $supply = Supply::query()->create([
                'supplier_order_item_id' => $lockedItem->id,
                'supplier_listing_id' => $lockedItem->supplier_listing_id,
                'order_ref' => $orderRef,
                'batch_number' => $lockedItem->batch_number,
                'unit_price' => $lockedItem->unit_price,
                'initial_quantity' => $totalQuantity,
                'quantity_in' => $totalQuantity,
                'quantity_out' => 0,
                'expiry_date' => $lockedItem->expiry_date,
                'delivery_date' => $deliveryDate,
                'is_in_stock' => true,
            ]);

            $this->recordInboundFromOrderItem($supply, $lockedItem, $user);

            $lockedItem->update([
                'is_in_supplies' => 'Stock',
                'moved_to_stock_at' => now(),
                'moved_to_stock_by' => $user?->id,
            ]);

            $lockedItem->supplierListing()->update([
                'price' => $lockedItem->unit_price,
            ]);

            $lockedItem->loadMissing('supplierListing.ingredient');

            $ingredient = $lockedItem->supplierListing?->ingredient;

            if ($ingredient && $lockedItem->unit_price !== null) {
                $ingredient->update([
                    'price' => $lockedItem->unit_price,
                ]);
            }

            return $supply;
        });
    }

    public function recordInboundFromOrderItem(Supply $supply, SupplierOrderItem $item, ?User $user = null): SuppliesMovement
    {
        return SuppliesMovement::query()->create([
            'supply_id' => $supply->id,
            'supplier_order_item_id' => $item->id,
            'production_id' => null,
            'user_id' => $user?->id,
            'movement_type' => 'in',
            'quantity' => ($item->quantity ?? 0) * ($item->unit_weight ?? 0),
            'unit' => 'kg',
            'reason' => 'Supplier order received into stock',
            'meta' => [
                'supplier_order_id' => $item->supplier_order_id,
                'order_ref' => $supply->order_ref,
                'batch_number' => $supply->batch_number,
            ],
            'moved_at' => now(),
        ]);
    }

    public function recordOutboundToProduction(Supply $supply, Production $production, float $quantityKg, ?User $user = null, ?string $reason = null): SuppliesMovement
    {
        return SuppliesMovement::query()->create([
            'supply_id' => $supply->id,
            'supplier_order_item_id' => $supply->supplier_order_item_id,
            'production_id' => $production->id,
            'user_id' => $user?->id,
            'movement_type' => 'out',
            'quantity' => $quantityKg,
            'unit' => 'kg',
            'reason' => $reason ?? 'Allocated to production',
            'meta' => [
                'production_batch' => $production->batch_number,
                'supply_batch' => $supply->batch_number,
            ],
            'moved_at' => now(),
        ]);
    }

    public function recordAdjustment(Supply $supply, float $quantityKg, string $reason, ?User $user = null): SuppliesMovement
    {
        return SuppliesMovement::query()->create([
            'supply_id' => $supply->id,
            'supplier_order_item_id' => $supply->supplier_order_item_id,
            'production_id' => null,
            'user_id' => $user?->id,
            'movement_type' => 'adjustment',
            'quantity' => $quantityKg,
            'unit' => 'kg',
            'reason' => $reason,
            'meta' => [
                'supply_batch' => $supply->batch_number,
            ],
            'moved_at' => now(),
        ]);
    }
}
