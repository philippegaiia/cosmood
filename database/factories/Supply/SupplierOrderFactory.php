<?php

namespace Database\Factories\Supply;

use App\Enums\OrderStatus;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierOrderFactory extends Factory
{
    protected $model = SupplierOrder::class;

    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'production_wave_id' => null,
            'serial_number' => $this->faker->numberBetween(1000, 9999),
            'order_status' => OrderStatus::Draft,
            'order_ref' => strtoupper($this->faker->bothify('PO-####')),
            'order_date' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'delivery_date' => $this->faker->dateTimeBetween('now', '+2 months'),
            'confirmation_number' => null,
            'invoice_number' => null,
            'bl_number' => null,
            'freight_cost' => null,
            'description' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_status' => OrderStatus::Draft,
        ]);
    }

    public function passed(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_status' => OrderStatus::Passed,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_status' => OrderStatus::Confirmed,
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_status' => OrderStatus::Delivered,
        ]);
    }

    public function forWave(ProductionWave $wave): static
    {
        return $this->state(fn (array $attributes) => [
            'production_wave_id' => $wave->id,
        ]);
    }
}
