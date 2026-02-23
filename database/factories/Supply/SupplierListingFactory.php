<?php

namespace Database\Factories\Supply;

use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierListingFactory extends Factory
{
    protected $model = SupplierListing::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'code' => strtoupper($this->faker->bothify('LIST-####')),
            'supplier_code' => strtoupper($this->faker->bothify('SUPCODE-####')),
            'supplier_id' => Supplier::factory(),
            'ingredient_id' => Ingredient::factory(),
            'unit_weight' => $this->faker->randomFloat(3, 1, 25),
            'price' => $this->faker->randomFloat(2, 5, 100),
            'organic' => false,
            'fairtrade' => false,
            'cosmos' => false,
            'ecocert' => false,
            'description' => $this->faker->sentence(),
            'is_active' => true,
            'unit_of_measure' => 'kg',
        ];
    }

    public function organic(): static
    {
        return $this->state(fn (array $attributes) => [
            'organic' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
