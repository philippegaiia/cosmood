<?php

namespace Database\Factories\Production;

use App\Enums\SizingMode;
use App\Models\Production\ProductCategory;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductTypeFactory extends Factory
{
    protected $model = ProductType::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'product_category_id' => ProductCategory::factory(),
            'default_production_line_id' => null,
            'qc_template_id' => null,
            'sizing_mode' => SizingMode::OilWeight,
            'default_batch_size' => $this->faker->randomFloat(3, 10, 50),
            'expected_units_output' => $this->faker->numberBetween(100, 500),
            'expected_waste_kg' => $this->faker->randomFloat(3, 0, 2),
            'unit_fill_size' => null,
            'is_active' => true,
        ];
    }

    public function soap(): static
    {
        return $this->state(fn (array $attributes) => [
            'sizing_mode' => SizingMode::OilWeight,
            'default_batch_size' => 26.0,
            'expected_units_output' => 288,
        ]);
    }

    public function balm(): static
    {
        return $this->state(fn (array $attributes) => [
            'sizing_mode' => SizingMode::FinalMass,
            'default_batch_size' => 10.0,
            'expected_units_output' => 333,
            'unit_fill_size' => 0.030,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function forCategory(ProductCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'product_category_id' => $category->id,
        ]);
    }

    public function withDefaultProductionLine(?ProductionLine $line = null): static
    {
        return $this->state(fn (): array => [
            'default_production_line_id' => $line?->id ?? ProductionLine::factory(),
        ]);
    }
}
