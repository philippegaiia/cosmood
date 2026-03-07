<?php

namespace Database\Factories\Production;

use App\Models\Production\ProductType;
use App\Models\Production\TaskTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskTemplateFactory extends Factory
{
    protected $model = TaskTemplate::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true).' Template',
            'product_category_id' => null,
        ];
    }

    public function forCategory(\App\Models\Production\ProductCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'product_category_id' => $category->id,
        ]);
    }

    public function forProductType(ProductType $productType, bool $isDefault = true): static
    {
        return $this->afterCreating(function (TaskTemplate $template) use ($productType, $isDefault): void {
            $template->productTypes()->attach($productType->id, ['is_default' => $isDefault]);
        });
    }

    public function default(): static
    {
        return $this->afterCreating(function (TaskTemplate $template): void {
            $productTypes = $template->productTypes;
            if ($productTypes->isNotEmpty()) {
                $template->productTypes()->updateExistingPivot($productTypes->first()->id, ['is_default' => true]);
            }
        });
    }
}
