<?php

namespace Database\Factories\Production;

use App\Enums\RequirementStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionPackagingRequirement;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionPackagingRequirementFactory extends Factory
{
    protected $model = ProductionPackagingRequirement::class;

    public function definition(): array
    {
        return [
            'production_id' => Production::factory(),
            'production_wave_id' => null,
            'packaging_name' => $this->faker->randomElement(['Pot 100ml', 'Flacon 50ml', 'Tube 75ml', 'Boîte carton', 'Étiquette']),
            'packaging_code' => strtoupper($this->faker->bothify('PKG-####')),
            'required_quantity' => $this->faker->numberBetween(50, 500),
            'supplier_id' => null,
            'unit_cost' => $this->faker->randomFloat(3, 0.05, 2.00),
            'status' => RequirementStatus::NotOrdered,
            'allocated_quantity' => 0,
            'notes' => null,
        ];
    }

    public function withWave(ProductionWave $wave): static
    {
        return $this->state(fn (array $attributes) => [
            'production_wave_id' => $wave->id,
        ]);
    }

    public function allocated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RequirementStatus::Allocated,
            'allocated_quantity' => $attributes['required_quantity'] ?? $this->faker->numberBetween(50, 500),
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

    public function withSupplier(Supplier $supplier): static
    {
        return $this->state(fn (array $attributes) => [
            'supplier_id' => $supplier->id,
        ]);
    }
}
