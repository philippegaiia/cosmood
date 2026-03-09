<?php

namespace Database\Factories\Production;

use App\Models\Production\ProductionTaskType;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionTaskTypeFactory extends Factory
{
    protected $model = ProductionTaskType::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'color' => $this->faker->hexColor(),
            'slug' => $this->faker->unique()->slug(),
            'duration' => $this->faker->numberBetween(30, 240),
            'description' => $this->faker->sentence(),
            'is_active' => true,
            'is_capacity_consuming' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function passive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_capacity_consuming' => false,
        ]);
    }
}
