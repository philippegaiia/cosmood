<?php

namespace Database\Factories\Production;

use App\Models\Production\Brand;
use App\Models\Production\Collection;
use App\Models\Production\Product;
use App\Models\Production\ProductCategory;
use App\Models\Production\ProductType;
use App\Models\Supply\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'product_category_id' => ProductCategory::factory(),
            'product_type_id' => null,
            'brand_id' => null,
            'collection_id' => null,
            'produced_ingredient_id' => null,
            'code' => strtoupper($this->faker->bothify('PRD-###')),
            'wp_code' => null,
            'name' => $this->faker->words(2, true),
            'launch_date' => $this->faker->date(),
            'net_weight' => $this->faker->randomFloat(3, 50, 200),
            'ean_code' => $this->faker->ean13(),
            'description' => $this->faker->sentence(),
            'is_active' => 1,
        ];
    }

    public function soap(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Savon 100g',
            'net_weight' => 100,
        ]);
    }

    public function balm(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Baume 30ml',
            'net_weight' => 30,
        ]);
    }

    public function withProductType(ProductType $productType): static
    {
        return $this->state(fn (array $attributes) => [
            'product_type_id' => $productType->id,
        ]);
    }

    public function withBrand(?Brand $brand = null): static
    {
        return $this->state(fn (): array => [
            'brand_id' => $brand?->id ?? Brand::factory(),
        ]);
    }

    public function withCollection(?Collection $collection = null): static
    {
        return $this->state(function () use ($collection): array {
            $resolvedCollection = $collection ?? Collection::factory()->create();

            return [
                'brand_id' => $resolvedCollection->brand_id,
                'collection_id' => $resolvedCollection->id,
            ];
        });
    }

    public function withProducedIngredient(?Ingredient $ingredient = null): static
    {
        return $this->state(fn (array $attributes) => [
            'produced_ingredient_id' => $ingredient?->id ?? Ingredient::factory()->manufactured(),
        ]);
    }
}
