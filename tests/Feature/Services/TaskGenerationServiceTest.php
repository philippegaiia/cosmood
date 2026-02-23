<?php

use App\Models\Production\Production;
use App\Models\Production\ProductType;
use App\Models\Production\TaskTemplate;
use App\Models\Production\TaskTemplateItem;
use App\Models\User;
use App\Services\Production\TaskGenerationService;
use Carbon\Carbon;

describe('TaskGenerationService', function () {
    beforeEach(function () {
        $this->service = app(TaskGenerationService::class);
    });

    describe('generateFromTemplate', function () {
        it('can generate tasks from a template', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            TaskTemplateItem::factory()->forTemplate($template)
                ->production()
                ->create(['offset_days' => 0]);

            TaskTemplateItem::factory()->forTemplate($template)
                ->cutting()
                ->create(['offset_days' => 2]);

            TaskTemplateItem::factory()->forTemplate($template)
                ->stamping()
                ->create(['offset_days' => 21]);

            $production = Production::factory()->confirmed()->create([
                'production_date' => '2026-03-02',
            ]);

            $this->service->generateFromTemplate($production, $template);

            $tasks = $production->fresh()->productionTasks;

            expect($tasks)->toHaveCount(3)
                ->and($tasks->first()->source)->toBe('template')
                ->and($tasks->first()->task_template_item_id)->not->toBeNull();
        });

        it('sets scheduled dates based on offset days', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            TaskTemplateItem::factory()->forTemplate($template)
                ->production()
                ->create(['offset_days' => 0]);

            TaskTemplateItem::factory()->forTemplate($template)
                ->cutting()
                ->create(['offset_days' => 2]);

            $production = Production::factory()->confirmed()->create([
                'production_date' => '2026-03-02',
            ]);

            $this->service->generateFromTemplate($production, $template);

            $tasks = $production->fresh()->productionTasks->sortBy('scheduled_date');

            expect($tasks->first()->scheduled_date->format('Y-m-d'))->toBe('2026-03-02')
                ->and($tasks->last()->scheduled_date->format('Y-m-d'))->toBe('2026-03-04');
        });

        it('skips weekends when configured', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            TaskTemplateItem::factory()->forTemplate($template)
                ->production()
                ->create([
                    'offset_days' => 0,
                    'skip_weekends' => true,
                ]);

            TaskTemplateItem::factory()->forTemplate($template)
                ->create([
                    'name' => 'After Weekend Task',
                    'offset_days' => 3,
                    'skip_weekends' => true,
                ]);

            $production = Production::factory()->create([
                'production_date' => '2026-03-06',
            ]);

            $this->service->generateFromTemplate($production, $template);

            $tasks = $production->fresh()->productionTasks->sortBy('scheduled_date');

            expect($tasks->last()->scheduled_date->format('Y-m-d'))->toBe('2026-03-11');
        });

        it('does not duplicate tasks on repeated calls', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            TaskTemplateItem::factory()->forTemplate($template)
                ->production()
                ->create();

            $production = Production::factory()->create();

            $this->service->generateFromTemplate($production, $template);
            $this->service->generateFromTemplate($production, $template);

            expect($production->productionTasks)->toHaveCount(1);
        });

        it('stores sequence and duration in minutes', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            TaskTemplateItem::factory()->forTemplate($template)->create([
                'name' => 'Stamping',
                'sort_order' => 3,
                'duration_minutes' => 180,
                'offset_days' => 2,
            ]);

            $production = Production::factory()->create();

            $this->service->generateFromTemplate($production, $template);

            $task = $production->fresh()->productionTasks->first();

            expect($task->sequence_order)->toBe(3)
                ->and($task->duration_minutes)->toBe(180)
                ->and($task->name)->toBe('Stamping');
        });
    });

    describe('cancelTasks', function () {
        it('can cancel all tasks for a production', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            TaskTemplateItem::factory()->forTemplate($template)
                ->production()
                ->create();

            TaskTemplateItem::factory()->forTemplate($template)
                ->cutting()
                ->create();

            $production = Production::factory()->create();

            $this->service->generateFromTemplate($production, $template);

            $this->service->cancelTasks($production, 'Production cancelled');

            $tasks = $production->fresh()->productionTasks;

            expect($tasks->every(fn ($task) => $task->cancelled_at !== null))->toBeTrue()
                ->and($tasks->first()->cancelled_reason)->toBe('Production cancelled');
        });

        it('does not cancel already finished tasks', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            TaskTemplateItem::factory()->forTemplate($template)
                ->production()
                ->create();

            TaskTemplateItem::factory()->forTemplate($template)
                ->cutting()
                ->create();

            $production = Production::factory()->create();

            $this->service->generateFromTemplate($production, $template);

            $production->productionTasks->first()->update(['is_finished' => true]);
            $production->refresh();

            $this->service->cancelTasks($production, 'Production cancelled');

            $production->load('productionTasks');
            $finishedTask = $production->productionTasks->where('is_finished', true)->first();
            $unfinishedTask = $production->productionTasks->where('is_finished', false)->first();

            expect($finishedTask->cancelled_at)->toBeNull()
                ->and($unfinishedTask->cancelled_at)->not->toBeNull();
        });
    });

    describe('scheduleTasks', function () {
        it('can reschedule tasks based on new production date', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            TaskTemplateItem::factory()->forTemplate($template)
                ->production()
                ->create(['offset_days' => 0]);

            TaskTemplateItem::factory()->forTemplate($template)
                ->cutting()
                ->create(['offset_days' => 2]);

            $production = Production::factory()->confirmed()->create([
                'production_date' => '2026-03-02',
            ]);

            $this->service->generateFromTemplate($production, $template);

            $production->update(['production_date' => '2026-03-10']);
            $production->refresh();

            $this->service->rescheduleTasks($production);

            $tasks = $production->fresh()->productionTasks->sortBy('scheduled_date');

            expect($tasks->first()->scheduled_date->format('Y-m-d'))->toBe('2026-03-10')
                ->and($tasks->last()->scheduled_date->format('Y-m-d'))->toBe('2026-03-12');
        });

        it('keeps manual schedule when auto rescheduling', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            $first = TaskTemplateItem::factory()->forTemplate($template)->create(['sort_order' => 1, 'offset_days' => 0]);
            $second = TaskTemplateItem::factory()->forTemplate($template)->create(['sort_order' => 2, 'offset_days' => 2]);

            $production = Production::factory()->confirmed()->create([
                'production_date' => '2026-03-02',
            ]);

            $this->service->generateFromTemplate($production, $template);

            $manualTask = $production->fresh()->productionTasks()->where('task_template_item_id', $second->id)->first();
            $this->service->setManualSchedule($manualTask, '2026-03-20');

            $production->update(['production_date' => '2026-03-10']);
            $production->refresh();

            $this->service->rescheduleTasks($production);

            $manualTask = $manualTask->fresh();
            expect($manualTask->scheduled_date->format('Y-m-d'))->toBe('2026-03-20')
                ->and($manualTask->is_manual_schedule)->toBeTrue();
        });

        it('can reset manual schedule to automatic', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            $item = TaskTemplateItem::factory()->forTemplate($template)->create(['sort_order' => 1, 'offset_days' => 0]);
            $production = Production::factory()->confirmed()->create([
                'production_date' => '2026-03-10',
            ]);

            $this->service->generateFromTemplate($production, $template);

            $task = $production->fresh()->productionTasks()->where('task_template_item_id', $item->id)->first();
            $this->service->setManualSchedule($task, '2026-03-20');

            $this->service->resetToAutoSchedule($task->fresh());

            $task = $task->fresh();
            expect($task->scheduled_date->format('Y-m-d'))->toBe('2026-03-20')
                ->and($task->is_manual_schedule)->toBeFalse();
        });

        it('updates following tasks when first sequence task date changes', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            $first = TaskTemplateItem::factory()->forTemplate($template)->create([
                'sort_order' => 1,
                'offset_days' => 0,
                'skip_weekends' => true,
            ]);

            $second = TaskTemplateItem::factory()->forTemplate($template)->create([
                'sort_order' => 2,
                'offset_days' => 2,
                'skip_weekends' => true,
            ]);

            $production = Production::factory()->confirmed()->create([
                'production_date' => '2026-03-02',
            ]);

            $this->service->generateFromTemplate($production, $template);

            $firstTask = $production->fresh()->productionTasks()->where('task_template_item_id', $first->id)->first();
            $this->service->setManualSchedule($firstTask, '2026-03-06');

            $production->refresh();
            $secondTask = $production->productionTasks()->where('task_template_item_id', $second->id)->first();

            expect($production->production_date->format('Y-m-d'))->toBe('2026-03-06')
                ->and($secondTask->scheduled_date->format('Y-m-d'))->toBe('2026-03-10');
        });
    });

    describe('task sequencing', function () {
        it('blocks finishing a task when previous tasks are not finished', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            $first = TaskTemplateItem::factory()->forTemplate($template)->create(['sort_order' => 1]);
            $second = TaskTemplateItem::factory()->forTemplate($template)->create(['sort_order' => 2]);

            $production = Production::factory()->create();
            $this->service->generateFromTemplate($production, $template);

            $secondTask = $production->fresh()->productionTasks()->where('task_template_item_id', $second->id)->first();

            expect(fn () => $this->service->markTaskAsFinished($secondTask))
                ->toThrow(InvalidArgumentException::class, 'Previous tasks must be finished first');
        });

        it('allows finishing tasks sequentially', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            $first = TaskTemplateItem::factory()->forTemplate($template)->create(['sort_order' => 1]);
            $second = TaskTemplateItem::factory()->forTemplate($template)->create(['sort_order' => 2]);

            $production = Production::factory()->create();
            $this->service->generateFromTemplate($production, $template);

            $firstTask = $production->fresh()->productionTasks()->where('task_template_item_id', $first->id)->first();
            $secondTask = $production->fresh()->productionTasks()->where('task_template_item_id', $second->id)->first();

            $this->service->markTaskAsFinished($firstTask);
            $this->service->markTaskAsFinished($secondTask->fresh());

            expect($secondTask->fresh()->is_finished)->toBeTrue();
        });

        it('can force finish blocked task with audit trail', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            TaskTemplateItem::factory()->forTemplate($template)->create(['sort_order' => 1]);
            $second = TaskTemplateItem::factory()->forTemplate($template)->create(['sort_order' => 2]);

            $production = Production::factory()->create();
            $this->service->generateFromTemplate($production, $template);

            $blockedTask = $production->fresh()->productionTasks()->where('task_template_item_id', $second->id)->first();
            $user = User::factory()->create();

            $this->service->forceFinishTask($blockedTask, $user, 'Urgent shipping deadline');

            $blockedTask = $blockedTask->fresh();

            expect($blockedTask->is_finished)->toBeTrue()
                ->and($blockedTask->dependency_bypassed_at)->not->toBeNull()
                ->and($blockedTask->dependency_bypassed_by)->toBe($user->id)
                ->and($blockedTask->dependency_bypass_reason)->toBe('Urgent shipping deadline');
        });

        it('requires reason for forced finish', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->create();

            TaskTemplateItem::factory()->forTemplate($template)->create(['sort_order' => 1]);
            $second = TaskTemplateItem::factory()->forTemplate($template)->create(['sort_order' => 2]);

            $production = Production::factory()->create();
            $this->service->generateFromTemplate($production, $template);

            $blockedTask = $production->fresh()->productionTasks()->where('task_template_item_id', $second->id)->first();
            $user = User::factory()->create();

            expect(fn () => $this->service->forceFinishTask($blockedTask, $user, '   '))
                ->toThrow(InvalidArgumentException::class, 'Reason is required to bypass dependencies');
        });
    });

    describe('getTaskTemplateForProduction', function () {
        it('finds default template for product type', function () {
            $productType = ProductType::factory()->create();
            $template = TaskTemplate::factory()->forProductType($productType)->default()->create();

            $production = Production::factory()->create([
                'product_type_id' => $productType->id,
            ]);

            $foundTemplate = $this->service->getTaskTemplateForProduction($production);

            expect($foundTemplate)->not->toBeNull()
                ->and($foundTemplate->id)->toBe($template->id);
        });

        it('returns null if no template found', function () {
            $production = Production::factory()->create();

            $foundTemplate = $this->service->getTaskTemplateForProduction($production);

            expect($foundTemplate)->toBeNull();
        });

        it('falls back to a global default template', function () {
            $productType = ProductType::factory()->create();
            $globalTemplate = TaskTemplate::factory()->default()->create([
                'product_type_id' => null,
            ]);

            $production = Production::factory()->create([
                'product_type_id' => $productType->id,
            ]);

            $foundTemplate = $this->service->getTaskTemplateForProduction($production);

            expect($foundTemplate)->not->toBeNull()
                ->and($foundTemplate->id)->toBe($globalTemplate->id);
        });
    });

    describe('calculateScheduledDate', function () {
        it('calculates date with offset days', function () {
            $startDate = Carbon::parse('2026-03-02');
            $offsetDays = 5;

            $scheduledDate = $this->service->calculateScheduledDate($startDate, $offsetDays, false);

            expect($scheduledDate->format('Y-m-d'))->toBe('2026-03-07');
        });

        it('skips weekends when enabled', function () {
            $startDate = Carbon::parse('2026-03-06');
            $offsetDays = 3;

            $scheduledDate = $this->service->calculateScheduledDate($startDate, $offsetDays, true);

            expect($scheduledDate->format('Y-m-d'))->toBe('2026-03-11');
        });

        it('handles zero offset', function () {
            $startDate = Carbon::parse('2026-03-06');

            $scheduledDate = $this->service->calculateScheduledDate($startDate, 0, true);

            expect($scheduledDate->format('Y-m-d'))->toBe('2026-03-06');
        });
    });
});
