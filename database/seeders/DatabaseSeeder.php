<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use Database\Seeders\Supply\SupplierSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // \App\Models\User::factory(10)->create();
        // Seed users
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

        // seed suppliers
        $this->call(SupplierSeeder::class);

        $this->call(SupplierContactSeeder::class);

        // $this->call(IngredientCategorySeeder::class);
        // $this->call(IngredientSeeder::class);
        $this->call(IngredientCategoriesTableSeeder::class);
        $this->call(IngredientsTableSeeder::class);
        $this->call(SupplierListingSeeder::class);
        $this->call(ProductCategorySeeder::class);
        $this->call(ProductTypeSeeder::class);
        $this->call(QcTemplateSeeder::class);
        $this->call(BatchSizePresetSeeder::class);
        $this->call(TaskTemplateSeeder::class);
        // $this->call(ProductSeeder::class);
        $this->call(ProductsTableSeeder::class);

        $this->call(FormulasTableSeeder::class);
        $this->call(FormulaItemsTableSeeder::class);
        $this->call(FormulaProductSeeder::class);

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

        // $this->call(FormulaProductTableSeeder::class);
    }
}
