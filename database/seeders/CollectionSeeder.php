<?php

namespace Database\Seeders;

use App\Models\Production\Brand;
use App\Models\Production\Collection;
use Illuminate\Database\Seeder;

class CollectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $collections = [];

        foreach ($collections as $collection) {
            $brand = Brand::query()
                ->where('slug', $collection['brand_slug'])
                ->first();

            if (! $brand) {
                continue;
            }

            Collection::query()->updateOrCreate(
                [
                    'brand_id' => $brand->id,
                    'slug' => $collection['slug'],
                ],
                [
                    'name' => $collection['name'],
                    'is_active' => $collection['is_active'] ?? true,
                ],
            );
        }
    }
}
