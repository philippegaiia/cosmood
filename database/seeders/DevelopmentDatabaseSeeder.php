<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DevelopmentDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ProductionDatabaseSeeder::class);

        $this->call(SupplierContactSeeder::class);

        $this->call(ProductionWaveSeeder::class);
        $this->call(ProductionSeeder::class);
        $this->call(ProductionTaskTypeSeeder::class);
        $this->call(ProductionTaskSeeder::class);
        $this->call(ProductionIngredientRequirementSeeder::class);
        $this->call(ProductionItemSeeder::class);

        $this->call(SupplierOrdersTableSeeder::class);
        $this->call(SupplierOrderItemsTableSeeder::class);
        $this->call(SuppliesTableSeeder::class);
        $this->call(SuppliesMovementSeeder::class);
    }
}
