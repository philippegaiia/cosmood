<?php

use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionTaskType;
use App\Models\Production\TaskTemplateItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ProductionTask Model', function () {
    it('can be created with factory', function () {
        $task = ProductionTask::factory()->create();

        expect($task)
            ->toBeInstanceOf(ProductionTask::class)
            ->and($task->name)->not->toBeEmpty();
    });

    it('belongs to a production', function () {
        $production = Production::factory()->create();
        $task = ProductionTask::factory()->create(['production_id' => $production->id]);

        expect($task->production->id)->toBe($production->id);
    });

    it('belongs to a production task type', function () {
        $taskType = ProductionTaskType::factory()->create();
        $task = ProductionTask::factory()->create(['production_task_type_id' => $taskType->id]);

        expect($task->productionTaskType->id)->toBe($taskType->id);
    });

    it('belongs to a template item', function () {
        $templateItem = TaskTemplateItem::factory()->create();
        $task = ProductionTask::factory()->create(['task_template_item_id' => $templateItem->id]);

        expect($task->templateItem->id)->toBe($templateItem->id);
    });

    it('can check if from template', function () {
        $task = ProductionTask::factory()->create(['source' => 'template']);

        expect($task->isFromTemplate())->toBeTrue();
    });

    it('can check if cancelled', function () {
        $task = ProductionTask::factory()->create(['cancelled_at' => now()]);

        expect($task->isCancelled())->toBeTrue();
    });

    it('can be cancelled with reason', function () {
        $task = ProductionTask::factory()->create();
        $task->cancel('Test cancellation');

        expect($task->fresh()->isCancelled())->toBeTrue()
            ->and($task->fresh()->cancelled_reason)->toBe('Test cancellation');
    });

    it('casts dates correctly', function () {
        $task = ProductionTask::factory()->create();

        expect($task->date)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
            ->and($task->scheduled_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    it('uses task type color and exposes lot references in calendar event', function () {
        $production = Production::factory()->create([
            'batch_number' => 'T00045',
            'permanent_batch_number' => 'P-00045',
        ]);

        $taskType = ProductionTaskType::factory()->create([
            'color' => '#123456',
        ]);

        $task = ProductionTask::factory()->create([
            'production_id' => $production->id,
            'production_task_type_id' => $taskType->id,
            'name' => 'Melange',
        ]);

        $task->load('production.product');
        $event = $task->toCalendarEvent();

        expect($event->getBackgroundColor())->toBe('#123456')
            ->and($event->getExtendedProps()['lotLabel'])->toBe('P-00045 (T00045)')
            ->and($event->getExtendedProps()['taskName'])->toBe('Melange')
            ->and($event->getExtendedProps()['url'])->toContain('/productions/'.$production->id);
    });
});
