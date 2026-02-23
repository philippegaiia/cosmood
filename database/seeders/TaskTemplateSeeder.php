<?php

namespace Database\Seeders;

use App\Models\Production\ProductType;
use App\Models\Production\TaskTemplate;
use App\Models\Production\TaskTemplateItem;
use Illuminate\Database\Seeder;

class TaskTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $productTypes = ProductType::query()->take(3)->get();

        if ($productTypes->isEmpty()) {
            $productTypes = ProductType::factory()->count(3)->create();
        }

        foreach ($productTypes as $productType) {
            $template = TaskTemplate::updateOrCreate(
                [
                    'name' => 'Template '.$productType->name,
                    'product_type_id' => $productType->id,
                ],
                [
                    'product_category_id' => $productType->product_category_id,
                    'is_default' => true,
                ]
            );

            $items = [
                ['name' => 'Production', 'duration_hours' => 4, 'duration_minutes' => 240, 'offset_days' => 0, 'skip_weekends' => true, 'sort_order' => 1],
                ['name' => 'Cutting', 'duration_hours' => 2, 'duration_minutes' => 120, 'offset_days' => 2, 'skip_weekends' => true, 'sort_order' => 2],
                ['name' => 'Stamping', 'duration_hours' => 2, 'duration_minutes' => 120, 'offset_days' => 21, 'skip_weekends' => true, 'sort_order' => 3],
                ['name' => 'Packing', 'duration_hours' => 4, 'duration_minutes' => 240, 'offset_days' => 28, 'skip_weekends' => true, 'sort_order' => 4],
            ];

            foreach ($items as $item) {
                TaskTemplateItem::updateOrCreate(
                    [
                        'task_template_id' => $template->id,
                        'name' => $item['name'],
                    ],
                    $item
                );
            }
        }
    }
}
