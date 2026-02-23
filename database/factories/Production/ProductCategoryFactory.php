<?php

namespace Database\Factories\Production;

use App\Models\Production\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'is_active' => true,
            'color' => $this->faker->hexColor(),
        ];
    }

    public function soap(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Savons',
        ]);
    }

    public function balm(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Baumes',
        ]);
    }

    public function deodorant(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Déodorants',
        ]);
    }
}
