<?php

namespace Database\Seeders;

use App\Models\Production\FormulaProduct;
use Illuminate\Database\Seeder;

class FormulaProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        FormulaProduct::factory()->count(10)->create();
    }
}
