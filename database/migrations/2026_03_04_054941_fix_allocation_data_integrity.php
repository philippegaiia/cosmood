<?php

use App\Models\Supply\Supply;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Recalculates allocated_quantity for all supplies from movements
     * to ensure data integrity.
     */
    public function up(): void
    {
        // Get all supplies with their movement totals
        $supplies = Supply::query()
            ->select('supplies.id')
            ->selectRaw('COALESCE(SUM(supplies_movements.quantity), 0) as movement_total')
            ->leftJoin('supplies_movements', function ($join) {
                $join->on('supplies.id', '=', 'supplies_movements.supply_id')
                    ->where('supplies_movements.movement_type', '=', 'allocation');
            })
            ->groupBy('supplies.id')
            ->get();

        // Update each supply's allocated_quantity
        foreach ($supplies as $supply) {
            Supply::where('id', $supply->id)->update([
                'allocated_quantity' => round($supply->movement_total, 3),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reverse data integrity fix
    }
};
