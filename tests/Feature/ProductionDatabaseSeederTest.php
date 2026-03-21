<?php

use App\Models\Production\Formula;
use App\Models\Production\FormulaProduct;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\User;
use Database\Seeders\ProductionDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('seeds the curated production-safe dataset idempotently', function () {
    Permission::findOrCreate('View:HomeDashboard');

    $this->seed(ProductionDatabaseSeeder::class);

    $countsAfterFirstRun = [
        'suppliers' => Supplier::query()->count(),
        'ingredients' => Ingredient::query()->count(),
        'formulas' => Formula::query()->count(),
        'formula_product_links' => FormulaProduct::query()->count(),
    ];

    expect(User::query()->where('email', 'admin@admin.com')->exists())->toBeTrue()
        ->and($countsAfterFirstRun['suppliers'])->toBeGreaterThan(0)
        ->and($countsAfterFirstRun['ingredients'])->toBeGreaterThan(0)
        ->and($countsAfterFirstRun['formulas'])->toBeGreaterThan(0)
        ->and(
            FormulaProduct::query()
                ->where('formula_id', 1)
                ->where('product_id', 1)
                ->where('is_default', true)
                ->exists()
        )->toBeTrue();

    $this->seed(ProductionDatabaseSeeder::class);

    expect(Supplier::query()->count())->toBe($countsAfterFirstRun['suppliers'])
        ->and(Ingredient::query()->count())->toBe($countsAfterFirstRun['ingredients'])
        ->and(Formula::query()->count())->toBe($countsAfterFirstRun['formulas'])
        ->and(FormulaProduct::query()->count())->toBe($countsAfterFirstRun['formula_product_links']);
});

it('uses the production-safe entry point as the default database seeder', function () {
    Permission::findOrCreate('View:HomeDashboard');

    $this->seed();

    expect(User::query()->where('email', 'admin@admin.com')->exists())->toBeTrue()
        ->and(Supplier::query()->exists())->toBeTrue()
        ->and(Ingredient::query()->exists())->toBeTrue();
});
