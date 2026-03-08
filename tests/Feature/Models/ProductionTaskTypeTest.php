<?php

use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionTaskType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ProductionTaskType Model', function () {
    it('can be created with factory', function () {
        $type = ProductionTaskType::factory()->create([
            'duration' => 90,
            'is_active' => true,
        ]);

        expect($type)->toBeInstanceOf(ProductionTaskType::class)
            ->and($type->name)->not->toBeEmpty();
    });

    it('has many production tasks', function () {
        $type = ProductionTaskType::factory()->create();
        $production = Production::factory()->create();

        ProductionTask::factory()->count(2)->create([
            'production_id' => $production->id,
            'production_task_type_id' => $type->id,
        ]);

        expect($type->productionTasks)->toHaveCount(2);
    });

    it('can be soft deleted', function () {
        $type = ProductionTaskType::factory()->create();

        $type->delete();

        expect($type->fresh()->deleted_at)->not->toBeNull();
    });

    it('persists color on create and update', function () {
        $type = ProductionTaskType::factory()->create([
            'color' => '#123456',
        ]);

        $type->update([
            'color' => '#654321',
        ]);

        expect($type->fresh()->color)->toBe('#654321');
    });
});
