<?php

namespace Database\Factories\Production;

use App\Models\Production\ProductionLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Production\ProductionLine>
 */
class ProductionLineFactory extends Factory
{
    protected $model = ProductionLine::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Ligne Savon 1',
            'Ligne Savon 2',
            'Laboratoire Deodorants',
            'Ligne Baumes',
        ]).' '.fake()->unique()->numberBetween(1, 99);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'daily_batch_capacity' => fake()->numberBetween(2, 8),
            'sort_order' => fake()->numberBetween(1, 20),
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function soapLine(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Ligne Savon',
            'slug' => 'ligne-savon',
            'daily_batch_capacity' => 4,
        ]);
    }

    public function deodorantLab(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Laboratoire Deodorants',
            'slug' => 'laboratoire-deodorants',
            'daily_batch_capacity' => 3,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
