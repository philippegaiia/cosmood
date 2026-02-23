<?php

namespace Database\Seeders;

use App\Models\Supply\SuppliesMovement;
use Illuminate\Database\Seeder;

class SuppliesMovementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SuppliesMovement::factory()->count(10)->create();
    }
}
