<?php

namespace Database\Factories\Supply;

use App\Models\Supply\Ingredient;
use App\Models\Supply\IngredientCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class IngredientFactory extends Factory
{
    protected $model = Ingredient::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'ingredient_category_id' => IngredientCategory::factory(),
            'name' => $name,
            'code' => strtoupper($this->faker->unique()->bothify('ING-######')),
            'slug' => Str::slug($name.'-'.$this->faker->unique()->numerify('###')),
            'name_en' => $name,
            'inci' => strtoupper($this->faker->word()),
            'inci_naoh' => null,
            'inci_koh' => null,
            'cas' => null,
            'cas_einecs' => null,
            'einecs' => null,
            'is_active' => true,
            'is_manufactured' => false,
            'description' => $this->faker->sentence(),
            'price' => $this->faker->randomFloat(2, 2, 40),
            'stock_min' => 0,
        ];
    }

    public function oil(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Huile de Coco',
            'inci' => 'Cocos Nucifera Oil',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function manufactured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_manufactured' => true,
        ]);
    }
}
