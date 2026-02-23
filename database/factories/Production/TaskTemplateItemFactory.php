<?php

namespace Database\Factories\Production;

use App\Models\Production\TaskTemplate;
use App\Models\Production\TaskTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskTemplateItemFactory extends Factory
{
    protected $model = TaskTemplateItem::class;

    public function definition(): array
    {
        return [
            'task_template_id' => TaskTemplate::factory(),
            'name' => $this->faker->words(2, true),
            'duration_hours' => $this->faker->numberBetween(1, 8),
            'duration_minutes' => $this->faker->randomElement([30, 45, 60, 90, 120, 180, 240]),
            'offset_days' => $this->faker->numberBetween(0, 30),
            'skip_weekends' => true,
            'sort_order' => 0,
        ];
    }

    public function production(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Production',
            'duration_hours' => 4,
            'duration_minutes' => 240,
            'offset_days' => 0,
            'sort_order' => 1,
        ]);
    }

    public function cutting(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Cutting',
            'duration_hours' => 2,
            'duration_minutes' => 120,
            'offset_days' => 2,
            'sort_order' => 2,
        ]);
    }

    public function stamping(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Stamping',
            'duration_hours' => 3,
            'duration_minutes' => 180,
            'offset_days' => 21,
            'sort_order' => 3,
        ]);
    }

    public function packing(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Packing',
            'duration_hours' => 4,
            'duration_minutes' => 240,
            'offset_days' => 28,
            'sort_order' => 4,
        ]);
    }

    public function forTemplate(TaskTemplate $template): static
    {
        return $this->state(fn (array $attributes) => [
            'task_template_id' => $template->id,
        ]);
    }
}
