<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Supply\IngredientCategorySeeder;
use Database\Seeders\Supply\IngredientSeeder;
use Database\Seeders\Supply\SupplierSeeder;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class ProductionDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $adminUser = User::query()->updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => bcrypt('password'),
            ]
        );

        $this->call(ShieldRolesSeeder::class);

        $adminUser->syncRoles([
            Role::findOrCreate(config('filament-shield.super_admin.name', 'super_admin')),
        ]);

        $this->call(SupplierSeeder::class);
        $this->call(SupplierContactSeeder::class);

        $this->call(IngredientCategorySeeder::class);
        $this->call(IngredientSeeder::class);
        $this->call(SupplierListingSeeder::class);

        $this->call(BrandSeeder::class);
        $this->call(CollectionSeeder::class);
        $this->call(DestinationSeeder::class);

        $this->call(ProductSeeder::class);
        $this->call(ProductTypeSeeder::class);
        $this->call(QcTemplateSeeder::class);
        $this->call(BatchSizePresetSeeder::class);

        $this->call(ProductionTaskTypeSeeder::class);
        $this->call(TaskTemplateSeeder::class);

        $this->call(FormulasTableSeeder::class);
        $this->call(FormulaItemsTableSeeder::class);
    }
}
