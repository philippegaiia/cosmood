<?php

namespace Database\Factories\Production;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Production\QcTemplate>
 */
class QcTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'QC '.$this->faker->words(2, true),
            'is_default' => false,
            'is_active' => true,
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function globalDefault(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
        ]);
    }
}
