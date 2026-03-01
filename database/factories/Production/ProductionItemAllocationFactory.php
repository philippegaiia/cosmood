<?php

namespace Database\Factories\Production;

use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Supply\Supply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Production\ProductionItemAllocation>
 */
class ProductionItemAllocationFactory extends Factory
{
    protected $model = ProductionItemAllocation::class;

    public function definition(): array
    {
        return [
            'production_item_id' => ProductionItem::factory(),
            'supply_id' => Supply::factory(),
            'quantity' => $this->faker->randomFloat(3, 1, 50),
            'status' => 'reserved',
            'reserved_at' => now(),
            'consumed_at' => null,
        ];
    }

    public function consumed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'consumed',
            'consumed_at' => now(),
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'released',
        ]);
    }
}
