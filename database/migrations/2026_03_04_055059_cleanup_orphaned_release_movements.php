<?php

use App\Models\Supply\SuppliesMovement;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Removes orphaned release movements that don't have corresponding allocations.
     * This ensures allocated quantities never go negative.
     */
    public function up(): void
    {
        // Get all supplies with allocation movements
        $supplyIds = SuppliesMovement::where('movement_type', 'allocation')
            ->distinct()
            ->pluck('supply_id');

        foreach ($supplyIds as $supplyId) {
            // Get all movements for this supply ordered by date
            $movements = SuppliesMovement::where('supply_id', $supplyId)
                ->where('movement_type', 'allocation')
                ->orderBy('moved_at')
                ->orderBy('id')
                ->get();

            $runningTotal = 0;
            $movementsToDelete = [];

            foreach ($movements as $movement) {
                $newTotal = $runningTotal + $movement->quantity;

                if ($newTotal < 0) {
                    // This release would make total negative - mark for deletion
                    $movementsToDelete[] = $movement->id;
                } else {
                    $runningTotal = $newTotal;
                }
            }

            // Delete orphaned release movements
            if (! empty($movementsToDelete)) {
                SuppliesMovement::whereIn('id', $movementsToDelete)->delete();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reverse cleanup
    }
};
