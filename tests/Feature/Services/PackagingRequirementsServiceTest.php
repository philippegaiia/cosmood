<?php

use App\Enums\RequirementStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionPackagingRequirement;
use App\Models\Supply\Supplier;
use App\Services\Production\PackagingRequirementsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(PackagingRequirementsService::class);
});

describe('generateRequirements', function () {
    it('generates packaging requirements from production expected units', function () {
        $production = Production::factory()->create([
            'expected_units' => 100,
        ]);

        $packagingData = [
            ['name' => 'Pot 100ml', 'code' => 'POT100', 'quantity_per_unit' => 1],
            ['name' => 'Couvercle', 'code' => 'COV100', 'quantity_per_unit' => 1],
            ['name' => 'Étiquette', 'code' => 'ETQ001', 'quantity_per_unit' => 1],
        ];

        $this->service->generateRequirements($production, $packagingData);

        $requirements = $production->fresh()->packagingRequirements;

        expect($requirements)->toHaveCount(3)
            ->and($requirements->where('packaging_name', 'Pot 100ml')->first()->required_quantity)->toBe(100)
            ->and($requirements->where('packaging_name', 'Couvercle')->first()->required_quantity)->toBe(100)
            ->and($requirements->where('packaging_name', 'Étiquette')->first()->required_quantity)->toBe(100);
    });

    it('calculates quantity based on units multiplier', function () {
        $production = Production::factory()->create([
            'expected_units' => 50,
        ]);

        $packagingData = [
            ['name' => 'Étiquette lot', 'code' => 'ETQ-LOT', 'quantity_per_unit' => 1],
            ['name' => 'Carton', 'code' => 'CRT-12', 'quantity_per_unit' => 0.0833],
        ];

        $this->service->generateRequirements($production, $packagingData);

        $requirements = $production->fresh()->packagingRequirements;

        expect($requirements)->toHaveCount(2)
            ->and($requirements->where('packaging_name', 'Étiquette lot')->first()->required_quantity)->toBe(50)
            ->and($requirements->where('packaging_name', 'Carton')->first()->required_quantity)->toBe(5);
    });

    it('sets wave_id from production', function () {
        $wave = \App\Models\Production\ProductionWave::factory()->create();
        $production = Production::factory()->create([
            'production_wave_id' => $wave->id,
            'expected_units' => 100,
        ]);

        $packagingData = [
            ['name' => 'Pot', 'code' => 'POT', 'quantity_per_unit' => 1],
        ];

        $this->service->generateRequirements($production, $packagingData);

        $requirement = $production->fresh()->packagingRequirements->first();
        expect($requirement->production_wave_id)->toBe($wave->id);
    });

    it('does not duplicate requirements if already exists', function () {
        $production = Production::factory()->create([
            'expected_units' => 100,
        ]);

        ProductionPackagingRequirement::factory()->create([
            'production_id' => $production->id,
            'packaging_name' => 'Pot 100ml',
            'packaging_code' => 'POT100',
            'required_quantity' => 100,
        ]);

        $packagingData = [
            ['name' => 'Pot 100ml', 'code' => 'POT100', 'quantity_per_unit' => 1],
            ['name' => 'Couvercle', 'code' => 'COV100', 'quantity_per_unit' => 1],
        ];

        $this->service->generateRequirements($production, $packagingData);

        $requirements = $production->fresh()->packagingRequirements;
        expect($requirements)->toHaveCount(2);
    });

    it('sets supplier if provided', function () {
        $supplier = Supplier::factory()->create();
        $production = Production::factory()->create([
            'expected_units' => 100,
        ]);

        $packagingData = [
            ['name' => 'Pot', 'code' => 'POT', 'quantity_per_unit' => 1, 'supplier_id' => $supplier->id],
        ];

        $this->service->generateRequirements($production, $packagingData);

        $requirement = $production->fresh()->packagingRequirements->first();
        expect($requirement->supplier_id)->toBe($supplier->id);
    });
});

describe('regenerateRequirements', function () {
    it('deletes existing requirements and regenerates', function () {
        $production = Production::factory()->create([
            'expected_units' => 100,
        ]);

        ProductionPackagingRequirement::factory()->create([
            'production_id' => $production->id,
            'packaging_name' => 'Old Packaging',
            'required_quantity' => 50,
        ]);

        $packagingData = [
            ['name' => 'New Pot', 'code' => 'NPOT', 'quantity_per_unit' => 1],
        ];

        $this->service->regenerateRequirements($production, $packagingData);

        $requirements = $production->fresh()->packagingRequirements;
        expect($requirements)->toHaveCount(1)
            ->and($requirements->first()->packaging_name)->toBe('New Pot')
            ->and($requirements->first()->required_quantity)->toBe(100);
    });
});

describe('updateQuantities', function () {
    it('updates packaging quantities when expected units change', function () {
        $production = Production::factory()->create([
            'expected_units' => 100,
        ]);

        ProductionPackagingRequirement::factory()->create([
            'production_id' => $production->id,
            'packaging_name' => 'Pot',
            'packaging_code' => 'POT',
            'required_quantity' => 100,
            'quantity_per_unit' => 1,
        ]);

        $production->update(['expected_units' => 150]);

        $this->service->updateQuantities($production);

        $requirement = $production->fresh()->packagingRequirements->first();
        expect($requirement->required_quantity)->toBe(150);
    });
});

describe('getSummary', function () {
    it('returns packaging summary by status', function () {
        $production = Production::factory()->create();

        ProductionPackagingRequirement::factory()->create([
            'production_id' => $production->id,
            'status' => RequirementStatus::NotOrdered,
        ]);

        ProductionPackagingRequirement::factory()->ordered()->create([
            'production_id' => $production->id,
        ]);

        ProductionPackagingRequirement::factory()->received()->create([
            'production_id' => $production->id,
        ]);

        ProductionPackagingRequirement::factory()->allocated()->create([
            'production_id' => $production->id,
        ]);

        $summary = $this->service->getSummary($production);

        expect($summary)
            ->toHaveKey('not_ordered', 1)
            ->toHaveKey('ordered', 1)
            ->toHaveKey('received', 1)
            ->toHaveKey('allocated', 1)
            ->toHaveKey('total', 4);
    });
});
