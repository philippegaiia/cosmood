<?php

namespace Database\Factories\Production;

use App\Models\Production\Brand;
use App\Models\Production\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Collection>
 */
class CollectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'brand_id' => Brand::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => true,
        ];
    }
}
