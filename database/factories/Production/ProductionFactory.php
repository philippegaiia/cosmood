<?php

namespace Database\Factories\Production;

use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductionFactory extends Factory
{
    protected $model = Production::class;

    public function definition(): array
    {
        return [
            'production_wave_id' => null,
            'product_id' => Product::factory(),
            'formula_id' => Formula::factory(),
            'product_type_id' => null,
            'batch_size_preset_id' => null,
            'parent_id' => null,
            'is_masterbatch' => false,
            'sizing_mode' => SizingMode::OilWeight,
            'planned_quantity' => $this->faker->randomFloat(3, 10, 50),
            'expected_units' => $this->faker->numberBetween(100, 500),
            'expected_waste_kg' => $this->faker->randomFloat(3, 0, 2),
            'actual_units' => null,
            'replaces_phase' => null,
            'masterbatch_lot_id' => null,
            'slug' => Str::slug('batch-'.$this->faker->unique()->numberBetween(1000, 9999)),
            'batch_number' => 'B'.$this->faker->unique()->numberBetween(1000, 9999),
            'status' => ProductionStatus::Planned,
            'production_date' => $this->faker->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'ready_date' => $this->faker->dateTimeBetween('+30 days', '+60 days')->format('Y-m-d'),
            'organic' => true,
            'notes' => null,
        ];
    }

    public function orphan(): static
    {
        return $this->state(fn (array $attributes) => [
            'production_wave_id' => null,
        ]);
    }

    public function forWave(ProductionWave $wave): static
    {
        return $this->state(fn (array $attributes) => [
            'production_wave_id' => $wave->id,
        ]);
    }

    public function masterbatch(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_masterbatch' => true,
            'replaces_phase' => 'saponified_oils',
        ]);
    }

    public function usingMasterbatch(Production $masterbatch): static
    {
        return $this->state(fn (array $attributes) => [
            'masterbatch_lot_id' => $masterbatch->id,
        ]);
    }

    public function planned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductionStatus::Planned,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductionStatus::Confirmed,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductionStatus::Ongoing,
        ]);
    }

    public function finished(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductionStatus::Finished,
            'actual_units' => $attributes['expected_units'] ?? $this->faker->numberBetween(100, 500),
        ]);
    }

    public function withProductType(ProductType $productType): static
    {
        return $this->state(fn (array $attributes) => [
            'product_type_id' => $productType->id,
            'sizing_mode' => $productType->sizing_mode,
            'planned_quantity' => $productType->default_batch_size,
            'expected_units' => $productType->expected_units_output,
        ]);
    }
}
