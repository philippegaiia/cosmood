<?php

use App\Enums\SizingMode;
use App\Models\Production\BatchSizePreset;
use App\Models\Production\ProductCategory;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductType;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
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

describe('ProductType Resource', function () {
    it('syncs allowed lines and default line when creating a product type', function () {
        $category = ProductCategory::factory()->create();
        $soapLineOne = ProductionLine::factory()->create(['name' => 'Soap Line 1']);
        $soapLineTwo = ProductionLine::factory()->create(['name' => 'Soap Line 2']);

        Livewire::test(\App\Filament\Resources\Production\ProductTypes\Pages\CreateProductType::class)
            ->fillForm([
                'name' => 'Savon barre',
                'slug' => 'savon-barre',
                'product_category_id' => $category->id,
                'sizing_mode' => SizingMode::OilWeight->value,
                'default_batch_size' => 26,
                'expected_units_output' => 288,
                'expected_waste_kg' => 0.5,
                'allowed_production_line_ids' => [$soapLineOne->id, $soapLineTwo->id],
                'default_production_line_id' => $soapLineOne->id,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productType = ProductType::query()->where('slug', 'savon-barre')->firstOrFail();

        expect($productType->default_production_line_id)->toBe($soapLineOne->id)
            ->and($productType->allowedProductionLines()->pluck('production_lines.id')->all())
            ->toEqualCanonicalizing([$soapLineOne->id, $soapLineTwo->id]);
    });

    it('clears the default and migrates planned productions when removing an allowed line', function () {
        $lineToRemove = ProductionLine::factory()->create(['name' => 'Soap Line 2']);
        $remainingLine = ProductionLine::factory()->create(['name' => 'Soap Line 1']);

        $productType = ProductType::factory()->create([
            'default_production_line_id' => $lineToRemove->id,
        ]);
        $productType->allowedProductionLines()->sync([$lineToRemove->id, $remainingLine->id]);

        $plannedProduction = Production::factory()->planned()->create([
            'product_type_id' => $productType->id,
            'production_line_id' => $lineToRemove->id,
        ]);

        Livewire::test(\App\Filament\Resources\Production\ProductTypes\Pages\EditProductType::class, [
            'record' => $productType->id,
        ])
            ->fillForm([
                'name' => $productType->name,
                'slug' => $productType->slug,
                'product_category_id' => $productType->product_category_id,
                'qc_template_id' => $productType->qc_template_id,
                'sizing_mode' => $productType->sizing_mode->value,
                'default_batch_size' => $productType->default_batch_size,
                'expected_units_output' => $productType->expected_units_output,
                'expected_waste_kg' => $productType->expected_waste_kg,
                'unit_fill_size' => $productType->unit_fill_size,
                'allowed_production_line_ids' => [$remainingLine->id],
                'default_production_line_id' => null,
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($productType->fresh()->default_production_line_id)->toBe($remainingLine->id)
            ->and($plannedProduction->fresh()->production_line_id)->toBe($remainingLine->id);
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
