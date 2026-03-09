<?php

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductType;
use App\Services\Production\ProductTypeProductionLineService;

describe('ProductTypeProductionLineService', function () {
    it('normalizes the default line against the allowed set', function () {
        $normalizedSelection = app(ProductTypeProductionLineService::class)->normalizeSelection(['', '3', 3, 5], 9);

        expect($normalizedSelection)->toBe([
            'allowed_production_line_ids' => [3, 5],
            'default_production_line_id' => null,
        ]);
    });

    it('auto-selects the only allowed line as default', function () {
        $line = ProductionLine::factory()->create();
        $productType = ProductType::factory()->create([
            'default_production_line_id' => null,
        ]);

        $summary = app(ProductTypeProductionLineService::class)->sync($productType, [$line->id], null);

        expect($summary['default_production_line_id'])->toBe($line->id)
            ->and($productType->fresh()->default_production_line_id)->toBe($line->id)
            ->and($productType->fresh()->load('allowedProductionLines')->allowedProductionLines->modelKeys())->toEqual([$line->id]);
    });

    it('migrates planned productions and keeps confirmed productions when a line is removed', function () {
        $removedLine = ProductionLine::factory()->create(['name' => 'Soap Line 2']);
        $defaultLine = ProductionLine::factory()->create(['name' => 'Soap Line 1']);
        $productType = ProductType::factory()->create([
            'default_production_line_id' => $removedLine->id,
        ]);
        $productType->allowedProductionLines()->sync([$removedLine->id, $defaultLine->id]);

        $plannedProduction = Production::factory()->planned()->create([
            'product_type_id' => $productType->id,
            'production_line_id' => $removedLine->id,
        ]);
        $confirmedProduction = Production::factory()->confirmed()->create([
            'product_type_id' => $productType->id,
            'production_line_id' => $removedLine->id,
        ]);
        $ongoingProduction = Production::factory()->inProgress()->create([
            'product_type_id' => $productType->id,
            'production_line_id' => $removedLine->id,
        ]);

        $summary = app(ProductTypeProductionLineService::class)->sync($productType, [$defaultLine->id], null);

        expect($summary['migrated_planned_count'])->toBe(1)
            ->and($summary['confirmed_conflict_count'])->toBe(1)
            ->and($summary['confirmed_conflict_line_names'])->toEqual(['Soap Line 2'])
            ->and($productType->fresh()->default_production_line_id)->toBe($defaultLine->id)
            ->and($plannedProduction->fresh()->production_line_id)->toBe($defaultLine->id)
            ->and($confirmedProduction->fresh()->production_line_id)->toBe($removedLine->id)
            ->and($ongoingProduction->fresh()->production_line_id)->toBe($removedLine->id);
    });

    it('can unassign planned productions when no default line remains', function () {
        $removedLine = ProductionLine::factory()->create();
        $productType = ProductType::factory()->create([
            'default_production_line_id' => $removedLine->id,
        ]);
        $productType->allowedProductionLines()->sync([$removedLine->id]);

        $plannedProduction = Production::factory()->create([
            'status' => ProductionStatus::Planned,
            'product_type_id' => $productType->id,
            'production_line_id' => $removedLine->id,
        ]);

        $summary = app(ProductTypeProductionLineService::class)->sync($productType, [], null);

        expect($summary['default_production_line_id'])->toBeNull()
            ->and($plannedProduction->fresh()->production_line_id)->toBeNull();
    });
});
