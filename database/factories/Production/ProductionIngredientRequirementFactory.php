<?php

namespace Database\Factories\Production;

use App\Enums\RequirementStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionIngredientRequirement;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionIngredientRequirementFactory extends Factory
{
    protected $model = ProductionIngredientRequirement::class;

    public function definition(): array
    {
        return [
            'production_id' => Production::factory(),
            'production_wave_id' => null,
            'ingredient_id' => Ingredient::factory(),
            'phase' => 'saponified_oils',
            'supplier_listing_id' => null,
            'required_quantity' => $this->faker->randomFloat(3, 1, 20),
            'status' => RequirementStatus::NotOrdered,
            'allocated_quantity' => 0,
            'allocated_from_supply_id' => null,
            'fulfilled_by_masterbatch_id' => null,
            'is_collapsed_in_ui' => false,
            'notes' => null,
        ];
    }

    public function allocated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RequirementStatus::Allocated,
            'allocated_quantity' => $attributes['required_quantity'] ?? $this->faker->randomFloat(3, 1, 20),
        ]);
    }

    public function ordered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RequirementStatus::Ordered,
        ]);
    }

    public function received(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RequirementStatus::Received,
        ]);
    }

    public function withWave(ProductionWave $wave): static
    {
        return $this->state(fn (array $attributes) => [
            'production_wave_id' => $wave->id,
        ]);
    }

    public function fulfilledByMasterbatch(Production $masterbatch): static
    {
        return $this->state(fn (array $attributes) => [
            'fulfilled_by_masterbatch_id' => $masterbatch->id,
            'is_collapsed_in_ui' => true,
            'status' => RequirementStatus::Allocated,
        ]);
    }
}
