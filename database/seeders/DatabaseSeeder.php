<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database using the production-safe curated dataset.
     */
    public function run(): void
    {
        $this->call(ProductionDatabaseSeeder::class);
    }
}
