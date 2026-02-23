<?php

use App\Models\Production\BatchSizePreset;
use App\Models\Production\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('BatchSizePreset Model', function () {
    it('can be created with factory', function () {
        $preset = BatchSizePreset::factory()->create();

        expect($preset)
            ->toBeInstanceOf(BatchSizePreset::class)
            ->and($preset->name)->not->toBeEmpty();
    });

    it('belongs to a product type', function () {
        $productType = ProductType::factory()->create();
        $preset = BatchSizePreset::factory()->create(['product_type_id' => $productType->id]);

        expect($preset->productType->id)->toBe($productType->id);
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

    it('can have double batch preset', function () {
        $preset = BatchSizePreset::factory()->create([
            'name' => 'Double Batch 52kg',
            'batch_size' => 52.0,
            'is_default' => false,
        ]);

        expect($preset->name)->toBe('Double Batch 52kg')
            ->and($preset->is_default)->toBeFalse();
    });
});
