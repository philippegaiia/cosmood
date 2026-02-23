<?php

namespace Database\Seeders;

use App\Models\Production\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $product_categories = [
            ['id' => '1', 'name' => 'Savons cosmétiques', 'is_active' => '1', 'deleted_at' => null, 'created_at' => '2021-01-24 05:44:21', 'updated_at' => '2021-01-24 06:07:06'],
            ['id' => '2', 'name' => 'Soins de la peau', 'is_active' => '1', 'deleted_at' => null, 'created_at' => '2021-01-24 05:46:10', 'updated_at' => '2021-01-24 05:46:10'],
            ['id' => '3', 'name' => 'Déodorants', 'is_active' => '1', 'deleted_at' => null, 'created_at' => '2021-01-24 05:46:25', 'updated_at' => '2021-01-24 05:46:25'],
            ['id' => '4', 'name' => 'Accessoires hygiène', 'is_active' => '1', 'deleted_at' => null, 'created_at' => '2021-01-24 05:46:46', 'updated_at' => '2021-01-24 05:46:46'],
            ['id' => '5', 'name' => 'Accessoires savonnerie', 'is_active' => '1', 'deleted_at' => null, 'created_at' => '2021-01-24 05:47:09', 'updated_at' => '2021-01-24 05:47:09'],
            ['id' => '6', 'name' => 'Soins capillaires', 'is_active' => '1', 'deleted_at' => null, 'created_at' => '2021-01-24 05:53:04', 'updated_at' => '2021-01-24 06:34:15'],
            ['id' => '7', 'name' => 'Shampoings', 'is_active' => '1', 'deleted_at' => null, 'created_at' => '2021-01-24 05:53:53', 'updated_at' => '2021-01-24 06:36:06'],
            ['id' => '8', 'name' => 'Autres', 'is_active' => '1', 'deleted_at' => null, 'created_at' => '2021-01-24 05:54:06', 'updated_at' => '2021-01-24 06:35:10'],
            ['id' => '9', 'name' => 'Savons ménagers', 'is_active' => '1', 'deleted_at' => null, 'created_at' => '2021-01-24 05:59:19', 'updated_at' => '2021-01-24 05:59:19'],
            ['id' => '10', 'name' => 'Arompathérapie', 'is_active' => '1', 'deleted_at' => null, 'created_at' => '2021-01-24 06:36:50', 'updated_at' => '2021-01-24 06:36:50'],
            ['id' => '11', 'name' => 'Hygiène bucco-dentaire', 'is_active' => '1', 'deleted_at' => null, 'created_at' => '2021-06-21 16:33:27', 'updated_at' => '2021-06-21 17:14:24'],
        ];

        foreach ($product_categories as $category) {
            ProductCategory::updateOrCreate(['id' => $category['id']], $category);
        }
    }
}
