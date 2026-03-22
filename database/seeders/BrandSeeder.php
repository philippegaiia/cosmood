<?php

namespace Database\Seeders;

use App\Models\Production\Brand;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $brands = [];

        foreach ($brands as $brand) {
            Brand::query()->updateOrCreate(
                ['slug' => $brand['slug']],
                $brand,
            );
        }
    }
}
