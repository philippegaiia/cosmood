<?php

namespace Database\Factories\Production;

use App\Enums\ProductionOutputKind;
use App\Models\Production\Production;
use App\Models\Supply\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Production\ProductionOutput>
 */
class ProductionOutputFactory extends Factory
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
            'kind' => ProductionOutputKind::MainProduct,
            'product_id' => null,
            'ingredient_id' => null,
            'quantity' => $this->faker->randomFloat(3, 1, 500),
            'unit' => 'u',
            'notes' => null,
        ];
    }

    public function mainProduct(): static
    {
        return $this->state(fn (): array => [
            'kind' => ProductionOutputKind::MainProduct,
            'ingredient_id' => null,
            'unit' => 'u',
        ]);
    }

    public function internalMainProduct(): static
    {
        return $this->state(fn (): array => [
            'kind' => ProductionOutputKind::MainProduct,
            'ingredient_id' => null,
            'unit' => 'kg',
        ]);
    }

    public function reworkMaterial(?Ingredient $ingredient = null): static
    {
        return $this->state(fn (): array => [
            'kind' => ProductionOutputKind::ReworkMaterial,
            'product_id' => null,
            'ingredient_id' => $ingredient?->id ?? Ingredient::factory()->manufactured(),
            'unit' => 'kg',
        ]);
    }

    public function scrap(): static
    {
        return $this->state(fn (): array => [
            'kind' => ProductionOutputKind::Scrap,
            'product_id' => null,
            'ingredient_id' => null,
            'unit' => 'kg',
        ]);
    }
}
