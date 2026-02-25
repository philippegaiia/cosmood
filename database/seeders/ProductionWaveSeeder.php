<?php

namespace Database\Seeders;

use App\Models\Production\ProductionWave;
use Illuminate\Database\Seeder;

class ProductionWaveSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (ProductionWave::query()->exists()) {
            return;
        }

        ProductionWave::factory()->draft()->count(2)->create();
        ProductionWave::factory()->approved()->count(2)->create();
        ProductionWave::factory()->inProgress()->create();
        ProductionWave::factory()->completed()->create();
    }
}
