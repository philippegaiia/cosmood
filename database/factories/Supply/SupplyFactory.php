<?php

namespace Database\Factories\Supply;

use App\Models\Supply\SupplierListing;
use App\Models\Supply\Supply;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplyFactory extends Factory
{
    protected $model = Supply::class;

    public function definition(): array
    {
        return [
            'supplier_listing_id' => SupplierListing::factory(),
            'supplier_order_item_id' => null,
            'order_ref' => strtoupper($this->faker->bothify('ORD-####')),
            'batch_number' => strtoupper($this->faker->bothify('BATCH-####')),
            'initial_quantity' => $this->faker->randomFloat(3, 10, 100),
            'quantity_in' => $this->faker->randomFloat(3, 10, 100),
            'quantity_out' => 0,
            'allocated_quantity' => 0,
            'unit_price' => $this->faker->randomFloat(2, 5, 50),
            'expiry_date' => $this->faker->dateTimeBetween('+6 months', '+2 years'),
            'delivery_date' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'is_in_stock' => true,
        ];
    }

    public function inStock(float $quantity = 50.0): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_in' => $quantity,
            'quantity_out' => 0,
            'allocated_quantity' => 0,
            'is_in_stock' => true,
        ]);
    }

    public function partiallyAllocated(float $total = 50.0, float $allocated = 20.0): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_in' => $total,
            'quantity_out' => 0,
            'allocated_quantity' => $allocated,
            'is_in_stock' => true,
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_in' => 0,
            'quantity_out' => 0,
            'allocated_quantity' => 0,
            'is_in_stock' => false,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expiry_date' => $this->faker->dateTimeBetween('-1 year', '-1 day'),
        ]);
    }
}
