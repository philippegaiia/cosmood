<?php

namespace Database\Factories\Production;

use App\Enums\Phases;
use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Supply\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

class FormulaItemFactory extends Factory
{
    protected $model = FormulaItem::class;

    public function definition(): array
    {
        return [
            'formula_id' => Formula::factory(),
            'ingredient_id' => Ingredient::factory(),
            'percentage_of_oils' => $this->faker->randomFloat(2, 1, 50),
            'phase' => Phases::Saponification->value,
            'organic' => true,
            'sort' => 0,
        ];
    }

    public function saponified(): static
    {
        return $this->state(fn (array $attributes) => [
            'phase' => Phases::Saponification->value,
        ]);
    }

    public function lye(): static
    {
        return $this->state(fn (array $attributes) => [
            'phase' => Phases::Lye->value,
        ]);
    }

    public function additive(): static
    {
        return $this->state(fn (array $attributes) => [
            'phase' => Phases::Additives->value,
        ]);
    }

    public function packaging(): static
    {
        return $this->state(fn (array $attributes) => [
            'phase' => Phases::Packaging->value,
        ]);
    }

    public function forFormula(Formula $formula): static
    {
        return $this->state(fn (array $attributes) => [
            'formula_id' => $formula->id,
        ]);
    }

    public function withIngredient(Ingredient $ingredient): static
    {
        return $this->state(fn (array $attributes) => [
            'ingredient_id' => $ingredient->id,
        ]);
    }

    public function percentage(float $percentage): static
    {
        return $this->state(fn (array $attributes) => [
            'percentage_of_oils' => $percentage,
        ]);
    }
}
