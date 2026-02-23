<?php

use App\Enums\SizingMode;
use App\Models\Production\BatchSizePreset;
use App\Models\Production\ProductCategory;
use App\Models\Production\ProductType;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('ProductType Model', function () {
    it('can be created with factory', function () {
        $productType = ProductType::factory()->create();

        expect($productType)
            ->toBeInstanceOf(ProductType::class)
            ->and($productType->name)->not->toBeEmpty();
    });

    it('can have batch size presets', function () {
        $productType = ProductType::factory()->create();
        BatchSizePreset::factory()->forProductType($productType)->standard()->create();
        BatchSizePreset::factory()->forProductType($productType)->half()->create();

        expect($productType->batchSizePresets)->toHaveCount(2);
    });

    it('can get default preset', function () {
        $productType = ProductType::factory()->create();
        BatchSizePreset::factory()->forProductType($productType)->half()->create();
        BatchSizePreset::factory()->forProductType($productType)->standard()->create();

        $default = $productType->defaultPreset();

        expect($default)->not->toBeNull()
            ->and($default->is_default)->toBeTrue();
    });

    it('returns null when no default preset exists', function () {
        $productType = ProductType::factory()->create();

        expect($productType->defaultPreset())->toBeNull();
    });
});

describe('ProductType - Sizing Modes', function () {
    it('can have oil weight sizing mode', function () {
        $productType = ProductType::factory()->soap()->create();

        expect($productType->sizing_mode)->toBe(SizingMode::OilWeight)
            ->and((float) $productType->default_batch_size)->toBe(26.0)
            ->and($productType->expected_units_output)->toBe(288);
    });

    it('can have final mass sizing mode', function () {
        $productType = ProductType::factory()->balm()->create();

        expect($productType->sizing_mode)->toBe(SizingMode::FinalMass)
            ->and((float) $productType->default_batch_size)->toBe(10.0)
            ->and((float) $productType->unit_fill_size)->toBe(0.030);
    });
});

describe('ProductType - Relationships', function () {
    it('belongs to a product category', function () {
        $category = ProductCategory::factory()->create();
        $productType = ProductType::factory()->forCategory($category)->create();

        expect($productType->productCategory->id)->toBe($category->id);
    });
});

describe('ProductType - Scopes', function () {
    it('can filter active product types', function () {
        ProductType::factory()->count(3)->create(['is_active' => true]);
        ProductType::factory()->count(2)->inactive()->create();

        $activeTypes = ProductType::where('is_active', true)->get();

        expect($activeTypes)->toHaveCount(3);
    });
});

describe('BatchSizePreset Model', function () {
    it('can be created with factory', function () {
        $preset = BatchSizePreset::factory()->create();

        expect($preset)
            ->toBeInstanceOf(BatchSizePreset::class)
            ->and($preset->name)->not->toBeEmpty();
    });

    it('can have standard preset', function () {
        $preset = BatchSizePreset::factory()->standard()->create();

        expect($preset->name)->toBe('Standard 26kg')
            ->and($preset->is_default)->toBeTrue();
    });

    it('can have half batch preset', function () {
        $preset = BatchSizePreset::factory()->half()->create();

        expect($preset->name)->toBe('Half Batch 14kg')
            ->and($preset->is_default)->toBeFalse();
    });
});
