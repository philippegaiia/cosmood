<?php

namespace Database\Factories\Supply;

use App\Models\Production\Production;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierOrderItemFactory extends Factory
{
    protected $model = SupplierOrderItem::class;

    public function definition(): array
    {
        return [
            'supplier_order_id' => SupplierOrder::factory(),
            'supplier_listing_id' => SupplierListing::factory(),
            'unit_weight' => $this->faker->randomFloat(3, 1, 25),
            'quantity' => $this->faker->randomFloat(3, 10, 100),
            'unit_price' => $this->faker->randomFloat(2, 5, 50),
            'batch_number' => strtoupper($this->faker->bothify('BATCH-####')),
            'expiry_date' => $this->faker->dateTimeBetween('+6 months', '+2 years'),
            'is_in_supplies' => 'Attente',
            'moved_to_stock_at' => null,
            'moved_to_stock_by' => null,
            'allocated_to_production_id' => null,
            'allocated_quantity' => 0,
            'committed_quantity_kg' => 0,
        ];
    }

    public function allocated(Production $production, float $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'allocated_to_production_id' => $production->id,
            'allocated_quantity' => $quantity,
        ]);
    }

    public function inSupplies(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_in_supplies' => 'Stock',
            'moved_to_stock_at' => now(),
        ]);
    }
}
