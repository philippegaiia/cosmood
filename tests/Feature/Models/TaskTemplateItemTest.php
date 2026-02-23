<?php

use App\Models\Production\ProductionTask;
use App\Models\Production\TaskTemplate;
use App\Models\Production\TaskTemplateItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('TaskTemplateItem Model', function () {
    it('can be created with factory', function () {
        $item = TaskTemplateItem::factory()->create();

        expect($item)
            ->toBeInstanceOf(TaskTemplateItem::class)
            ->and($item->name)->not->toBeEmpty();
    });

    it('belongs to a task template', function () {
        $template = TaskTemplate::factory()->create();
        $item = TaskTemplateItem::factory()->create(['task_template_id' => $template->id]);

        expect($item->taskTemplate->id)->toBe($template->id);
    });

    it('has many production tasks', function () {
        $item = TaskTemplateItem::factory()->create();
        $production = \App\Models\Production\Production::factory()->create();
        ProductionTask::factory()->count(2)->create([
            'task_template_item_id' => $item->id,
            'production_id' => $production->id,
        ]);

        expect($item->productionTasks)->toHaveCount(2);
    });

    it('casts skip_weekends as boolean', function () {
        $item = TaskTemplateItem::factory()->create(['skip_weekends' => true]);

        expect($item->skip_weekends)->toBeTrue();
    });

    it('has duration in minutes', function () {
        $item = TaskTemplateItem::factory()->create(['duration_minutes' => 150]);

        expect($item->duration_minutes)->toBe(150);
    });
});
