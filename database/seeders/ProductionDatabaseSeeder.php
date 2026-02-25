<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Supply\IngredientCategorySeeder;
use Database\Seeders\Supply\IngredientSeeder;
use Database\Seeders\Supply\SupplierSeeder;
use Illuminate\Database\Seeder;

class ProductionDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Test Admin',
                'password' => bcrypt('password'),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'user@user.com'],
            [
                'name' => 'Test Utilisateur',
                'password' => bcrypt('password'),
            ]
        );

        $this->call(SupplierSeeder::class);

        $this->call(IngredientCategorySeeder::class);
        $this->call(IngredientSeeder::class);
        $this->call(SupplierListingSeeder::class);

        $this->call(ProductSeeder::class);
        $this->call(ProductTypeSeeder::class);
        $this->call(QcTemplateSeeder::class);
        $this->call(BatchSizePresetSeeder::class);
        $this->call(TaskTemplateSeeder::class);

        $this->call(FormulasTableSeeder::class);
        $this->call(FormulaItemsTableSeeder::class);
    }
}
