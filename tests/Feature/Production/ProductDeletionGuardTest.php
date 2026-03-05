<?php

use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prevents deleting a product linked to productions', function (): void {
    $product = Product::factory()->create();
    $formula = Formula::query()->create([
        'name' => 'Formule test',
        'slug' => 'formule-test',
        'code' => 'FRM-TST-001',
        'is_active' => true,
    ]);

    Production::factory()
        ->for($product, 'product')
        ->for($formula, 'formula')
        ->create();

    expect(fn () => $product->delete())
        ->toThrow(\InvalidArgumentException::class);

    expect($product->fresh())->not->toBeNull();
    expect($product->fresh()?->deleted_at)->toBeNull();
});

it('allows deleting a product without productions', function (): void {
    $product = Product::factory()->create();

    $product->delete();

    expect(Product::withTrashed()->find($product->id)?->deleted_at)->not->toBeNull();
});
