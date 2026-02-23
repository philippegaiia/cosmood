<?php

use App\Enums\RequirementStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionPackagingRequirement;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ProductionPackagingRequirement Model', function () {
    it('can be created with factory', function () {
        $requirement = ProductionPackagingRequirement::factory()->create();

        expect($requirement)
            ->toBeInstanceOf(ProductionPackagingRequirement::class)
            ->and($requirement->packaging_name)->not->toBeEmpty();
    });

    it('belongs to a production', function () {
        $production = Production::factory()->create();
        $requirement = ProductionPackagingRequirement::factory()->create(['production_id' => $production->id]);

        expect($requirement->production->id)->toBe($production->id);
    });

    it('belongs to a wave', function () {
        $wave = ProductionWave::factory()->create();
        $requirement = ProductionPackagingRequirement::factory()->create(['production_wave_id' => $wave->id]);

        expect($requirement->wave->id)->toBe($wave->id);
    });

    it('belongs to a supplier', function () {
        $supplier = Supplier::factory()->create();
        $requirement = ProductionPackagingRequirement::factory()->create(['supplier_id' => $supplier->id]);

        expect($requirement->supplier->id)->toBe($supplier->id);
    });

    it('casts status as enum', function () {
        $requirement = ProductionPackagingRequirement::factory()->create(['status' => RequirementStatus::NotOrdered]);

        expect($requirement->status)->toBeInstanceOf(RequirementStatus::class)
            ->and($requirement->status)->toBe(RequirementStatus::NotOrdered);
    });

    it('can check if allocated', function () {
        $requirement = ProductionPackagingRequirement::factory()->allocated()->create();

        expect($requirement->isAllocated())->toBeTrue();
    });

    it('calculates remaining quantity', function () {
        $requirement = ProductionPackagingRequirement::factory()->create([
            'required_quantity' => 100,
            'allocated_quantity' => 25,
        ]);

        expect($requirement->getRemainingQuantity())->toBe(75);
    });
});
