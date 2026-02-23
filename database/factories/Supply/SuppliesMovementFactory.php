<?php

namespace Database\Factories\Supply;

use App\Models\Supply\SuppliesMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Supply\SuppliesMovement>
 */
class SuppliesMovementFactory extends Factory
{
    protected $model = SuppliesMovement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }
}
