<?php

namespace Database\Factories\Production;

use App\Models\Production\BatchSizePreset;
use App\Models\Production\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;

class BatchSizePresetFactory extends Factory
{
    protected $model = BatchSizePreset::class;

    public function definition(): array
    {
        return [
            'product_type_id' => ProductType::factory(),
            'name' => $this->faker->words(2, true),
            'batch_size' => $this->faker->randomFloat(3, 10, 50),
            'expected_units' => $this->faker->numberBetween(100, 500),
            'expected_waste_kg' => $this->faker->randomFloat(3, 0, 2),
            'is_default' => false,
        ];
    }

    public function standard(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Standard 26kg',
            'batch_size' => 26.0,
            'expected_units' => 288,
            'is_default' => true,
        ]);
    }

    public function half(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Half Batch 14kg',
            'batch_size' => 14.0,
            'expected_units' => 144,
            'is_default' => false,
        ]);
    }

    public function forProductType(ProductType $productType): static
    {
        return $this->state(fn (array $attributes) => [
            'product_type_id' => $productType->id,
        ]);
    }
}
