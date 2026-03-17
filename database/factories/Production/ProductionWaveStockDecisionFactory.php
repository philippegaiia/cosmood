<?php

namespace Database\Factories\Production;

use App\Models\Production\ProductionWave;
use App\Models\Production\ProductionWaveStockDecision;
use App\Models\Supply\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionWaveStockDecision>
 */
class ProductionWaveStockDecisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_wave_id' => ProductionWave::factory(),
            'ingredient_id' => Ingredient::factory(),
            'reserved_quantity' => fake()->randomFloat(3, 1, 100),
        ];
    }
}
