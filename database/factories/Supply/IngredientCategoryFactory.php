<?php

namespace Database\Factories\Supply;

use App\Models\Supply\IngredientCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class IngredientCategoryFactory extends Factory
{
    protected $model = IngredientCategory::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'name' => $name,
            'code' => strtoupper($this->faker->unique()->bothify('CAT-####')),
            'slug' => Str::slug($name.'-'.$this->faker->unique()->numerify('###')),
            'parent_id' => null,
            'is_visible' => true,
            'description' => $this->faker->sentence(),
        ];
    }

    public function oils(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Huiles',
            'code' => 'OIL',
        ]);
    }

    public function additives(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Additifs',
            'code' => 'ADD',
        ]);
    }
}
