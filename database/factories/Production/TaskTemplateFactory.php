<?php

namespace Database\Factories\Production;

use App\Models\Production\ProductType;
use App\Models\Production\TaskTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskTemplateFactory extends Factory
{
    protected $model = TaskTemplate::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true).' Template',
            'product_category_id' => null,
            'product_type_id' => ProductType::factory(),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function forCategory(\App\Models\Production\ProductCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'product_category_id' => $category->id,
            'product_type_id' => null,
        ]);
    }

    public function forProductType(ProductType $productType): static
    {
        return $this->state(fn (array $attributes) => [
            'product_type_id' => $productType->id,
            'product_category_id' => $productType->product_category_id,
        ]);
    }
}
