<?php

namespace Database\Seeders;

use App\Models\Production\ProductionTaskType;
use App\Models\Production\TaskTemplate;
use Illuminate\Database\Seeder;

class TaskTemplateTaskTypeSeeder extends Seeder
{
    public function run(): void
    {
        // Get all task templates
        $templates = TaskTemplate::all();

        // Get all production task types
        $taskTypes = ProductionTaskType::all();

        if ($taskTypes->isEmpty()) {
            // Create default task types if none exist
            $taskTypes = $this->createDefaultTaskTypes();
        }

        // Attach task types to each template
        foreach ($templates as $template) {
            // Skip if already has task types attached
            if ($template->taskTypes()->count() > 0) {
                continue;
            }

            // Attach 3-5 task types with different configurations
            $taskTypes->random(min(3, $taskTypes->count()))->each(function ($taskType, $index) use ($template) {
                $template->taskTypes()->attach($taskType->id, [
                    'sort_order' => $index,
                    'offset_days' => $index * 2,
                    'skip_weekends' => true,
                    'duration_override' => null, // Use default duration
                ]);
            });
        }

        $this->command->info('Task types attached to '.$templates->count().' templates.');
    }

    private function createDefaultTaskTypes()
    {
        $types = [
            ['name' => 'Pesée', 'duration' => 60, 'slug' => 'pesee'],
            ['name' => 'Fabrication', 'duration' => 240, 'slug' => 'fabrication'],
            ['name' => 'Découpe', 'duration' => 120, 'slug' => 'decoupe'],
            ['name' => 'Tamponnage', 'duration' => 90, 'slug' => 'tamponnage'],
            ['name' => 'Conditionnement', 'duration' => 180, 'slug' => 'conditionnement'],
        ];

        $created = collect();
        foreach ($types as $type) {
            $created->push(ProductionTaskType::firstOrCreate(
                ['slug' => $type['slug']],
                $type
            ));
        }

        return $created;
    }
}
