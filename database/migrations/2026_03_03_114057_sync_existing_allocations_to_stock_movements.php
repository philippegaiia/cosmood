<?php

use App\Models\Production\ProductionItemAllocation;
use App\Models\Supply\SuppliesMovement;
use App\Models\Supply\Supply;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Syncs existing reserved allocations to stock movements and allocated_quantity.
     * This fixes data from before the allocation system was properly integrated with
     * the stock movement tracking.
     */
    public function up(): void
    {
        // Find all reserved allocations
        $allocations = ProductionItemAllocation::query()
            ->where('status', 'reserved')
            ->with(['productionItem.production', 'supply.supplierListing'])
            ->get();

        DB::transaction(function () use ($allocations): void {
            foreach ($allocations as $allocation) {
                $supply = $allocation->supply;
                $production = $allocation->productionItem->production;

                if (! $supply || ! $production) {
                    continue;
                }

                // Check if movement already exists for this allocation
                $existingMovement = SuppliesMovement::query()
                    ->where('supply_id', $supply->id)
                    ->where('production_id', $production->id)
                    ->where('movement_type', 'allocation')
                    ->where('quantity', $allocation->quantity)
                    ->exists();

                if ($existingMovement) {
                    // Movement exists, just update allocated_quantity if needed
                    $this->updateAllocatedQuantity($supply);

                    continue;
                }

                // Create missing movement
                SuppliesMovement::query()->create([
                    'supply_id' => $supply->id,
                    'supplier_order_item_id' => $supply->supplier_order_item_id,
                    'production_id' => $production->id,
                    'user_id' => null,
                    'movement_type' => 'allocation',
                    'quantity' => $allocation->quantity,
                    'unit' => $supply->supplierListing?->unit_of_measure ?: 'kg',
                    'reason' => 'Reserved for production (migrated)',
                    'meta' => [
                        'production_batch' => $production->getLotIdentifier(),
                        'supply_batch' => $supply->batch_number,
                        'migration' => true,
                    ],
                    'moved_at' => $allocation->reserved_at ?? now(),
                ]);

                // Update allocated_quantity
                $this->updateAllocatedQuantity($supply);
            }
        });
    }

    /**
     * Update allocated_quantity on a supply based on current active allocations.
     */
    private function updateAllocatedQuantity(Supply $supply): void
    {
        $allocated = ProductionItemAllocation::query()
            ->where('supply_id', $supply->id)
            ->where('status', 'reserved')
            ->sum('quantity');

        $supply->update(['allocated_quantity' => round($allocated, 3)]);
    }

    /**
     * Reverse the migrations.
     *
     * Removes the migration-generated movements. Note: This won't restore
     * allocated_quantity values - that would require a backup.
     */
    public function down(): void
    {
        SuppliesMovement::query()
            ->where('movement_type', 'allocation')
            ->whereJsonContains('meta->migration', true)
            ->delete();
    }
};
