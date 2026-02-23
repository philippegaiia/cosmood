<?php

namespace Database\Factories\Production;

use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionTaskType;
use App\Models\Production\TaskTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionTaskFactory extends Factory
{
    protected $model = ProductionTask::class;

    public function definition(): array
    {
        return [
            'production_id' => Production::factory(),
            'production_task_type_id' => ProductionTaskType::factory(),
            'task_template_item_id' => null,
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'date' => $this->faker->dateTimeBetween('now', '+2 weeks'),
            'scheduled_date' => $this->faker->dateTimeBetween('now', '+2 weeks'),
            'duration_minutes' => $this->faker->numberBetween(30, 480),
            'sequence_order' => null,
            'is_finished' => false,
            'is_manual_schedule' => false,
            'dependency_bypassed_at' => null,
            'dependency_bypassed_by' => null,
            'dependency_bypass_reason' => null,
            'source' => 'manual',
            'cancelled_at' => null,
            'cancelled_reason' => null,
        ];
    }

    public function fromTemplate(TaskTemplateItem $item): static
    {
        return $this->state(fn (array $attributes) => [
            'task_template_item_id' => $item->id,
            'name' => $item->name,
            'duration_minutes' => $item->duration_minutes,
            'sequence_order' => $item->sort_order,
            'source' => 'template',
        ]);
    }

    public function finished(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_finished' => true,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'cancelled_at' => now(),
            'cancelled_reason' => $this->faker->sentence(),
        ]);
    }
}
