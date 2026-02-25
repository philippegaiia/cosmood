<?php

namespace Database\Factories\Production;

use App\Enums\QcInputType;
use App\Models\Production\QcTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Production\QcTemplateItem>
 */
class QcTemplateItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'qc_template_id' => QcTemplate::factory(),
            'code' => null,
            'label' => $this->faker->words(3, true),
            'input_type' => $this->faker->randomElement([
                QcInputType::Number,
                QcInputType::Text,
            ]),
            'unit' => 'kg',
            'min_value' => null,
            'max_value' => null,
            'target_value' => null,
            'options' => null,
            'stage' => 'final_release',
            'required' => true,
            'sort_order' => $this->faker->numberBetween(1, 20),
        ];
    }

    public function numeric(): static
    {
        return $this->state(fn (): array => [
            'input_type' => QcInputType::Number,
            'unit' => 'g',
            'min_value' => 95,
            'max_value' => 105,
        ]);
    }
}
