<?php

namespace Database\Factories\Production;

use App\Enums\QcInputType;
use App\Enums\QcResult;
use App\Models\Production\Production;
use App\Models\Production\QcTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Production\ProductionQcCheck>
 */
class ProductionQcCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_id' => Production::factory(),
            'qc_template_item_id' => QcTemplateItem::factory(),
            'code' => strtoupper($this->faker->bothify('QC-###')),
            'label' => $this->faker->words(2, true),
            'input_type' => QcInputType::Number,
            'unit' => 'g',
            'min_value' => 95,
            'max_value' => 105,
            'target_value' => null,
            'options' => null,
            'stage' => 'final_release',
            'required' => true,
            'sort_order' => 1,
            'value_number' => null,
            'value_text' => null,
            'value_boolean' => null,
            'result' => QcResult::Pending,
            'checked_at' => null,
            'checked_by' => null,
            'notes' => null,
        ];
    }

    public function passed(): static
    {
        return $this->state(fn (): array => [
            'value_number' => 100,
            'result' => QcResult::Pass,
        ]);
    }
}
