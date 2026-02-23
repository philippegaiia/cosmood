<?php

namespace Database\Seeders;

use App\Models\Production\Production;
use App\Models\Production\ProductionPackagingRequirement;
use Illuminate\Database\Seeder;

class ProductionPackagingRequirementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $productions = Production::query()->take(12)->get();

        if ($productions->isEmpty()) {
            $productions = Production::factory()->count(12)->create();
        }

        foreach ($productions as $production) {
            ProductionPackagingRequirement::factory()->count(3)->create([
                'production_id' => $production->id,
                'production_wave_id' => $production->production_wave_id,
            ]);
        }
    }
}
