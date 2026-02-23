<?php

namespace Database\Factories\Production;

use App\Models\Production\Formula;
use App\Models\Production\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FormulaFactory extends Factory
{
    protected $model = Formula::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'product_id' => Product::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'code' => strtoupper($this->faker->bothify('FML-######')),
            'dip_number' => null,
            'is_active' => true,
            'date_of_creation' => $this->faker->date(),
            'description' => $this->faker->sentence(),
            'replaces_phase' => null,
        ];
    }

    public function masterbatch(): static
    {
        return $this->state(fn (array $attributes) => [
            'replaces_phase' => 'saponified_oils',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
