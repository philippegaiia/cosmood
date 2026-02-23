<?php

namespace Database\Factories\Production;

use App\Enums\Phases;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Production\ProductionItem>
 */
class ProductionItemFactory extends Factory
{
    protected $model = ProductionItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_id' => Production::factory(),
            'ingredient_id' => Ingredient::factory(),
            'supplier_listing_id' => SupplierListing::factory(),
            'supply_id' => null,
            'supply_batch_number' => null,
            'percentage_of_oils' => $this->faker->randomFloat(2, 1, 35),
            'phase' => $this->faker->randomElement([
                Phases::Saponification->value,
                Phases::Additives->value,
            ]),
            'organic' => true,
            'is_supplied' => false,
            'sort' => $this->faker->numberBetween(1, 20),
        ];
    }

    public function supplied(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_supplied' => true,
        ]);
    }

    public function additive(): static
    {
        return $this->state(fn (array $attributes) => [
            'phase' => Phases::Additives->value,
        ]);
    }

    public function withoutSupplierListing(): static
    {
        return $this->state(fn (array $attributes) => [
            'supplier_listing_id' => null,
            'supply_id' => null,
            'supply_batch_number' => null,
        ]);
    }
}
