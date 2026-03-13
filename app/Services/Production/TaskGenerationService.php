<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Models\Production\Holiday;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\Production\TaskTemplate;
use App\Models\User;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Manages generation, sequencing, and scheduling lifecycle for production tasks.
 */
class TaskGenerationService
{
    /**
     * Creates missing tasks from template items while preserving order.
     */
    public function generateFromTemplate(Production $production, TaskTemplate $template): void
    {
        $templateDefinitions = $this->getTemplateTaskDefinitions($template);
        $lastScheduledDate = null;

        foreach ($templateDefinitions as $definition) {
            if ($this->productionAlreadyHasTemplateTask($production, $definition)) {
                continue;
            }

            $scheduledDate = $this->resolveScheduledDate(
                $production->production_date,
                $definition['offset_days'],
                $definition['skip_weekends'],
                $lastScheduledDate
            );

            $this->createProductionTask($production, $definition, $scheduledDate);
            $lastScheduledDate = $scheduledDate;
        }
    }

    /**
     * Cancels all unfinished and non-cancelled tasks for a production.
     */
    public function cancelTasks(Production $production, ?string $reason = null): void
    {
        $production->productionTasks()
            ->where('is_finished', false)
            ->whereNull('cancelled_at')
            ->update([
                'cancelled_at' => now(),
                'cancelled_reason' => $reason,
            ]);
    }

    /**
     * Recalculates scheduled dates for template-based tasks.
     */
    public function rescheduleTasks(Production $production, bool $force = false): void
    {
        $template = $this->getTaskTemplateForProduction($production);
        $templateLookups = $this->buildTemplateLookups($template);

        /**
         * Load tasks explicitly because this method is also reached from observers
         * after date updates, where lazy loading is blocked in non-production.
         */
        $production->loadMissing(['productionTasks', 'productionTasks.templateItem']);

        $tasks = $production->productionTasks
            ->filter(fn (ProductionTask $task): bool => $task->production_task_type_id !== null || $task->task_template_item_id !== null)
            ->sortBy(fn (ProductionTask $task): array => [
                $task->sequence_order ?? PHP_INT_MAX,
                $task->id,
            ])
            ->values();

        $lastScheduledDate = null;

        foreach ($tasks as $task) {
            if ($this->shouldPreserveCurrentScheduledDate($task, $force)) {
                if ($task->scheduled_date) {
                    $lastScheduledDate = Carbon::parse($task->scheduled_date);
                }

                continue;
            }

            if ($this->isAnchorTask($task)) {
                $anchorDate = Carbon::parse($production->production_date);

                $this->updateTaskSchedule($task, $anchorDate);
                $lastScheduledDate = $anchorDate;

                continue;
            }

            $schedulingRule = $this->resolveTaskSchedulingRule($task, $templateLookups);

            if ($schedulingRule === null) {
                if ($task->scheduled_date) {
                    $lastScheduledDate = Carbon::parse($task->scheduled_date);
                }

                continue;
            }

            $scheduledDate = $this->resolveScheduledDate(
                $production->production_date,
                $schedulingRule['offset_days'],
                $schedulingRule['skip_weekends'],
                $lastScheduledDate
            );

            $this->updateTaskSchedule($task, $scheduledDate);
            $lastScheduledDate = $scheduledDate;
        }
    }

    /**
     * Marks a task as finished while enforcing dependency order unless bypassed.
     */
    public function markTaskAsFinished(ProductionTask $task, bool $bypassSequence = false): void
    {
        $this->assertTaskCanBeExecuted($task);

        if ($task->isCancelled()) {
            throw new InvalidArgumentException(__('Cancelled task cannot be finished'));
        }

        if ($task->is_finished) {
            return;
        }

        if (! $bypassSequence && $this->hasUnfinishedPredecessor($task)) {
            throw new InvalidArgumentException(__('Previous tasks must be finished first'));
        }

        $task->update([
            'is_finished' => true,
            'dependency_bypassed_at' => null,
            'dependency_bypassed_by' => null,
            'dependency_bypass_reason' => null,
        ]);
    }

    /**
     * Force-finishes a task and records bypass audit details.
     */
    public function forceFinishTask(ProductionTask $task, User $user, string $reason): void
    {
        $this->assertTaskCanBeExecuted($task);

        if ($task->isCancelled()) {
            throw new InvalidArgumentException(__('Cancelled task cannot be finished'));
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(__('Reason is required to bypass dependencies'));
        }

        if ($task->is_finished) {
            return;
        }

        $task->update([
            'is_finished' => true,
            'dependency_bypassed_at' => now(),
            'dependency_bypassed_by' => $user->id,
            'dependency_bypass_reason' => $reason,
        ]);
    }

    /**
     * Returns whether completion is blocked by earlier unfinished dependencies.
     */
    public function isBlockedByDependencies(ProductionTask $task): bool
    {
        return $this->hasUnfinishedPredecessor($task);
    }

    /**
     * Sets a manual date for one task while preserving first-task anchoring rules.
     *
     * Invariants:
     * - Sequence #1 template task is always anchored to production_date.
     * - Moving sequence #1 updates production_date and keeps the task auto-scheduled.
     * - Moving other tasks marks only that task as manual (no cascading shift).
     */
    public function setManualSchedule(ProductionTask $task, Carbon|string $scheduledDate): void
    {
        if ($task->is_finished || $task->isCancelled()) {
            throw new InvalidArgumentException('Finished or cancelled task cannot be rescheduled');
        }

        $date = Carbon::parse($scheduledDate);

        if ($this->isAnchorTask($task)) {
            $task->update([
                'scheduled_date' => $date,
                'date' => $date,
                'is_manual_schedule' => false,
            ]);

            $production = $this->resolveProduction($task);

            if ($production) {
                $production->update([
                    'production_date' => $date->toDateString(),
                ]);
            }

            return;
        }

        $task->update([
            'scheduled_date' => $date,
            'date' => $date,
            'is_manual_schedule' => true,
        ]);
    }

    /**
     * Restores one task to automatic scheduling.
     */
    public function resetToAutoSchedule(ProductionTask $task): void
    {
        if (($task->production_task_type_id === null && $task->task_template_item_id === null) || $task->is_finished || $task->isCancelled()) {
            return;
        }

        $task->update([
            'is_manual_schedule' => false,
        ]);

        $production = $this->resolveProduction($task);

        if (! $production) {
            return;
        }

        $this->rescheduleTasks($production, false);
    }

    /**
     * Detects unfinished predecessor tasks in template sequence.
     */
    protected function hasUnfinishedPredecessor(ProductionTask $task): bool
    {
        if ($task->source !== 'template' || $task->sequence_order === null) {
            return false;
        }

        return ProductionTask::query()
            ->where('production_id', $task->production_id)
            ->where('source', 'template')
            ->where('sequence_order', '<', $task->sequence_order)
            ->where('is_finished', false)
            ->exists();
    }

    /**
     * Resolves the default task template for a production.
     */
    public function getTaskTemplateForProduction(Production $production): ?TaskTemplate
    {
        $productTypeId = $production->product_type_id;

        if (! $productTypeId && $production->product_id) {
            $productTypeId = Product::query()
                ->whereKey($production->product_id)
                ->value('product_type_id');
        }

        if (! $productTypeId) {
            return null;
        }

        // Get template from pivot table relationship
        $specificTemplate = TaskTemplate::whereHas('productTypes', function ($query) use ($productTypeId) {
            $query->where('product_types.id', $productTypeId)
                ->where('product_type_task_template.is_default', true);
        })
            ->with('taskTemplateTaskTypes.taskType')
            ->first();

        if ($specificTemplate) {
            return $specificTemplate;
        }

        // Fallback to global default (template not linked to any product type)
        return TaskTemplate::doesntHave('productTypes')
            ->with('taskTemplateTaskTypes.taskType')
            ->first();
    }

    /**
     * Resolves the production for a task without triggering lazy loading.
     */
    private function resolveProduction(ProductionTask $task): ?Production
    {
        if ($task->relationLoaded('production')) {
            return $task->production;
        }

        if (! $task->production_id) {
            return null;
        }

        return Production::query()->find($task->production_id);
    }

    /**
     * Ensures a task is executable in the current production context.
     *
     * Task completion belongs to execution, not planning. A task may only be
     * finished once the parent production is ongoing and the task is due on or
     * before today. This keeps the domain contract aligned with the production
     * lifecycle even if another UI path bypasses relation-manager visibility.
     */
    private function assertTaskCanBeExecuted(ProductionTask $task): void
    {
        $production = $this->resolveProduction($task);

        if (! $production || $production->status !== ProductionStatus::Ongoing) {
            throw new InvalidArgumentException(__('Tasks can only be completed while the production is ongoing'));
        }

        if ($task->scheduled_date !== null && Carbon::parse($task->scheduled_date)->isFuture()) {
            throw new InvalidArgumentException(__('Task cannot be completed before its scheduled date'));
        }
    }

    /**
     * Normalizes task template sources into one task-definition shape.
     *
     * @return array<int, array{
     *     name: string,
     *     production_task_type_id: int|null,
     *     task_template_item_id: int|null,
     *     sequence_order: int|null,
     *     offset_days: int,
     *     skip_weekends: bool,
     *     duration_minutes: int
     * }>
     */
    private function getTemplateTaskDefinitions(TaskTemplate $template): array
    {
        $template->loadMissing(['taskTemplateTaskTypes.taskType', 'items']);

        if ($template->taskTemplateTaskTypes->isNotEmpty()) {
            return $template->taskTemplateTaskTypes
                ->filter(fn ($pivot): bool => $pivot->taskType !== null)
                ->map(fn ($pivot): array => [
                    'name' => $pivot->taskType->name,
                    'production_task_type_id' => $pivot->taskType->id,
                    'task_template_item_id' => null,
                    'sequence_order' => $pivot->sort_order,
                    'offset_days' => $pivot->offset_days,
                    'skip_weekends' => $pivot->skip_weekends,
                    'duration_minutes' => $pivot->duration_override ?? $pivot->taskType->duration ?? 60,
                ])
                ->values()
                ->all();
        }

        return $template->items
            ->map(fn ($item): array => [
                'name' => $item->name,
                'production_task_type_id' => null,
                'task_template_item_id' => $item->id,
                'sequence_order' => $item->sort_order,
                'offset_days' => $item->offset_days,
                'skip_weekends' => $item->skip_weekends,
                'duration_minutes' => $item->duration_minutes,
            ])
            ->values()
            ->all();
    }

    /**
     * Persists one normalized template task onto a production.
     *
     * @param  array{
     *     name: string,
     *     production_task_type_id: int|null,
     *     task_template_item_id: int|null,
     *     sequence_order: int|null,
     *     duration_minutes: int
     * }  $definition
     */
    private function createProductionTask(Production $production, array $definition, Carbon $scheduledDate): void
    {
        ProductionTask::create([
            'production_id' => $production->id,
            'task_template_item_id' => $definition['task_template_item_id'],
            'name' => $definition['name'],
            'description' => null,
            'production_task_type_id' => $definition['production_task_type_id'],
            'source' => 'template',
            'sequence_order' => $definition['sequence_order'],
            'scheduled_date' => $scheduledDate,
            'date' => $scheduledDate,
            'duration_minutes' => $definition['duration_minutes'],
            'is_finished' => false,
            'is_manual_schedule' => false,
            'cancelled_at' => null,
            'cancelled_reason' => null,
            'notes' => null,
        ]);
    }

    /**
     * Determines whether a normalized template task already exists on the production.
     *
     * @param  array{production_task_type_id: int|null, task_template_item_id: int|null}  $definition
     */
    private function productionAlreadyHasTemplateTask(Production $production, array $definition): bool
    {
        $query = $production->productionTasks()->where('source', 'template');

        if ($definition['production_task_type_id'] !== null) {
            return $query
                ->where('production_task_type_id', $definition['production_task_type_id'])
                ->exists();
        }

        if ($definition['task_template_item_id'] === null) {
            return false;
        }

        return $query
            ->where('task_template_item_id', $definition['task_template_item_id'])
            ->exists();
    }

    /**
     * Builds fast template lookups for task-type and legacy template-item scheduling rules.
     *
     * @return array{
     *     task_types: array<int, array{offset_days: int, skip_weekends: bool, sort_order: int|null}>,
     *     template_items: array<int, array{offset_days: int, skip_weekends: bool, sort_order: int|null}>
     * }
     */
    private function buildTemplateLookups(?TaskTemplate $template): array
    {
        if ($template === null) {
            return [
                'task_types' => [],
                'template_items' => [],
            ];
        }

        $template->loadMissing(['taskTemplateTaskTypes.taskType', 'items']);

        return [
            'task_types' => $template->taskTemplateTaskTypes
                ->filter(fn ($pivot): bool => $pivot->taskType !== null)
                ->mapWithKeys(fn ($pivot): array => [
                    $pivot->taskType->id => [
                        'offset_days' => $pivot->offset_days,
                        'skip_weekends' => $pivot->skip_weekends,
                        'sort_order' => $pivot->sort_order,
                    ],
                ])
                ->all(),
            'template_items' => $template->items
                ->mapWithKeys(fn ($item): array => [
                    $item->id => [
                        'offset_days' => $item->offset_days,
                        'skip_weekends' => $item->skip_weekends,
                        'sort_order' => $item->sort_order,
                    ],
                ])
                ->all(),
        ];
    }

    /**
     * Resolves scheduling metadata for a production task from template lookups or legacy template item.
     *
     * @param  array{
     *     task_types: array<int, array{offset_days: int, skip_weekends: bool, sort_order: int|null}>,
     *     template_items: array<int, array{offset_days: int, skip_weekends: bool, sort_order: int|null}>
     * }  $templateLookups
     * @return array{offset_days: int, skip_weekends: bool, sort_order: int|null}|null
     */
    private function resolveTaskSchedulingRule(ProductionTask $task, array $templateLookups): ?array
    {
        if ($task->production_task_type_id !== null) {
            return $templateLookups['task_types'][$task->production_task_type_id] ?? null;
        }

        if ($task->task_template_item_id !== null) {
            $templateItemRule = $templateLookups['template_items'][$task->task_template_item_id] ?? null;

            if ($templateItemRule !== null) {
                return $templateItemRule;
            }
        }

        if ($task->task_template_item_id !== null && $task->templateItem !== null) {
            return [
                'offset_days' => $task->templateItem->offset_days,
                'skip_weekends' => $task->templateItem->skip_weekends,
                'sort_order' => $task->templateItem->sort_order,
            ];
        }

        return null;
    }

    /**
     * Returns whether the task should keep its current scheduled date during auto-reschedule.
     */
    private function shouldPreserveCurrentScheduledDate(ProductionTask $task, bool $force): bool
    {
        if ($task->is_finished || $task->isCancelled()) {
            return true;
        }

        return ! $force && $task->is_manual_schedule;
    }

    /**
     * Returns whether the task is the template anchor that must match production_date.
     */
    protected function isAnchorTask(ProductionTask $task): bool
    {
        return $task->source === 'template' && $task->sequence_order === 1;
    }

    /**
     * Applies one scheduled date update while clearing manual mode.
     */
    private function updateTaskSchedule(ProductionTask $task, Carbon $scheduledDate): void
    {
        $task->update([
            'scheduled_date' => $scheduledDate,
            'date' => $scheduledDate,
            'is_manual_schedule' => false,
        ]);
    }

    /**
     * Calculates one scheduled date and keeps sequence monotonicity.
     */
    private function resolveScheduledDate(
        Carbon|string $productionDate,
        int $offsetDays,
        bool $skipWeekends,
        ?Carbon $lastScheduledDate
    ): Carbon {
        $scheduledDate = $this->calculateScheduledDate($productionDate, $offsetDays, $skipWeekends);

        if ($lastScheduledDate !== null && $scheduledDate->lt($lastScheduledDate)) {
            return $lastScheduledDate->copy();
        }

        return $scheduledDate;
    }

    /**
     * Calculates one scheduled date from a start date and day offset.
     * Skips weekends and holidays when configured.
     */
    public function calculateScheduledDate($startDate, int $offsetDays, bool $skipWeekends, bool $skipHolidays = true): Carbon
    {
        $date = Carbon::parse($startDate)->copy();

        if ($offsetDays === 0) {
            return $date;
        }

        $daysAdded = 0;

        while ($daysAdded < $offsetDays) {
            $date->addDay();

            if ($skipWeekends && $date->isWeekend()) {
                continue;
            }

            if ($skipHolidays && Holiday::isHoliday($date)) {
                continue;
            }

            $daysAdded++;
        }

        return $date;
    }

    /**
     * Generates tasks using the resolved template for a production.
     */
    public function generateTasksForProduction(Production $production): bool
    {
        $template = $this->getTaskTemplateForProduction($production);

        if (! $template) {
            return false;
        }

        $this->generateFromTemplate($production, $template);

        return true;
    }
}
