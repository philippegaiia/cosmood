<?php

namespace Database\Seeders\Supply;

use App\Models\Supply\IngredientCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class IngredientCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $json = File::get('database/data/ingredient_categories.json');
        $ingredient_categories = json_decode($json, true);
        foreach ($ingredient_categories as $category) {
            IngredientCategory::create([
                'id' => $category['id'],
                'name' => $category['name'],
                'code' => $category['code'],
                'slug' => $category['slug'],
                'parent_id' => $category['parent_id'],
                'is_visible' => $category['is_visible'],
                // 'description' => $category['description'],
                'deleted_at' => $category['deleted_at'],
                'created_at' => $category['created_at'],
                'updated_at' => $category['updated_at'],
            ]);
        }
    }
}
