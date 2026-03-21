<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DevelopmentDatabaseSeeder extends Seeder
{
    /**
     * Seed the development database.
     *
     * This currently mirrors the production-safe curated dataset. Additional
     * demo-only records should be appended here when we intentionally re-add
     * them, rather than altering the production-safe entry point.
     */
    public function run(): void
    {
        $this->call(ProductionDatabaseSeeder::class);
    }
}
