<?php

namespace Database\Factories\Production;

use App\Models\Production\FormulaProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Production\FormulaProduct>
 */
class FormulaProductFactory extends Factory
{
    protected $model = FormulaProduct::class;

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
