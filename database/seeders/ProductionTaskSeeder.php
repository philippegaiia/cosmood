<?php

namespace Database\Seeders;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionTaskType;
use Illuminate\Database\Seeder;

class ProductionTaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $productions = Production::query()
            ->whereIn('status', [
                ProductionStatus::Confirmed,
                ProductionStatus::Ongoing,
                ProductionStatus::Finished,
            ])
            ->take(12)
            ->get();

        if ($productions->isEmpty()) {
            $productions = Production::factory()->count(12)->confirmed()->create();
        }

        $taskTypes = ProductionTaskType::query()->get();

        if ($taskTypes->isEmpty()) {
            $taskTypes = ProductionTaskType::factory()->count(5)->create();
        }

        foreach ($productions as $production) {
            ProductionTask::factory()->count(3)->create([
                'production_id' => $production->id,
                'production_task_type_id' => $taskTypes->random()->id,
            ]);
        }
    }
}
