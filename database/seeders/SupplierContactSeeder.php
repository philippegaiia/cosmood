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
        $suppliers = Supplier::query()->take(8)->get();

        if ($suppliers->isEmpty()) {
            $suppliers = Supplier::factory()->count(8)->create();
        }

        foreach ($suppliers as $supplier) {
            SupplierContact::factory()->count(2)->create([
                'supplier_id' => $supplier->id,
            ]);
        }
    }
}
