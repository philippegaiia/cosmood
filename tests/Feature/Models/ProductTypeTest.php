<?php

use App\Enums\SizingMode;
use App\Models\Production\BatchSizePreset;
use App\Models\Production\ProductCategory;
use App\Models\Production\ProductType;

describe('ProductType Model', function () {
    it('can be created with factory', function () {
        $productType = ProductType::factory()->create();

        expect($productType)
            ->toBeInstanceOf(ProductType::class)
            ->and($productType->name)->not->toBeEmpty()
            ->and($productType->slug)->not->toBeEmpty();
    });

    it('has correct default sizing mode', function () {
        $productType = ProductType::factory()->create();

        expect($productType->sizing_mode)->toBe(SizingMode::OilWeight);
    });

    it('can have soap sizing mode', function () {
        $productType = ProductType::factory()->soap()->create();

        expect($productType->sizing_mode)->toBe(SizingMode::OilWeight)
            ->and((float) $productType->default_batch_size)->toBe(26.0)
            ->and($productType->expected_units_output)->toBe(288);
    });

    it('can have balm sizing mode', function () {
        $productType = ProductType::factory()->balm()->create();

        expect($productType->sizing_mode)->toBe(SizingMode::FinalMass)
            ->and((float) $productType->default_batch_size)->toBe(10.0)
            ->and((float) $productType->unit_fill_size)->toBe(0.030);
    });

    it('belongs to a product category', function () {
        $category = ProductCategory::factory()->create();
        $productType = ProductType::factory()->forCategory($category)->create();

        expect($productType->productCategory->id)->toBe($category->id);
    });

    it('can have batch size presets', function () {
        $productType = ProductType::factory()->create();
        $preset = BatchSizePreset::factory()->forProductType($productType)->create();

        expect($productType->batchSizePresets)->toHaveCount(1)
            ->and($productType->batchSizePresets->first()->id)->toBe($preset->id);
    });

    it('can get default preset', function () {
        $productType = ProductType::factory()->create();
        BatchSizePreset::factory()->forProductType($productType)->half()->create();
        BatchSizePreset::factory()->forProductType($productType)->standard()->create();

        $defaultPreset = $productType->defaultPreset();

        expect($defaultPreset)->not->toBeNull()
            ->and($defaultPreset->is_default)->toBeTrue()
            ->and($defaultPreset->name)->toBe('Standard 26kg');
    });

    it('returns null when no default preset exists', function () {
        $productType = ProductType::factory()->create();

        expect($productType->defaultPreset())->toBeNull();
    });
});

describe('ProductType Scopes', function () {
    it('can filter active product types', function () {
        ProductType::factory()->count(3)->create(['is_active' => true]);
        ProductType::factory()->count(2)->inactive()->create();

        $activeTypes = ProductType::where('is_active', true)->get();

        expect($activeTypes)->toHaveCount(3);
    });
});
