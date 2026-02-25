<?php

namespace Database\Seeders;

use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierContact;
use Illuminate\Database\Seeder;

class SupplierContactSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (SupplierContact::query()->exists()) {
            return;
        }

        $suppliers = Supplier::query()->take(8)->get();

        if ($suppliers->isEmpty()) {
            return;
        }

        foreach ($suppliers as $supplier) {
            SupplierContact::factory()->count(2)->create([
                'supplier_id' => $supplier->id,
            ]);
        }
    }
}
