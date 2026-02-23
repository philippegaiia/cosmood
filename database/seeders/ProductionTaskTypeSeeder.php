<?php

namespace Database\Seeders;

use App\Models\Production\ProductionTaskType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductionTaskTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $taskTypes = [
            ['name' => 'Pesée', 'duration' => 60],
            ['name' => 'Fabrication', 'duration' => 240],
            ['name' => 'Découpe', 'duration' => 120],
            ['name' => 'Tamponnage', 'duration' => 90],
            ['name' => 'Conditionnement', 'duration' => 180],
        ];

        foreach ($taskTypes as $taskType) {
            ProductionTaskType::updateOrCreate(
                ['slug' => Str::slug($taskType['name'])],
                [
                    'name' => $taskType['name'],
                    'duration' => $taskType['duration'],
                    'is_active' => true,
                ]
            );
        }
    }
}
