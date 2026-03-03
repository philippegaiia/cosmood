<?php

namespace App\Services\Production;

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
        // Eager load relationships to prevent lazy loading violations
        $template->load('taskTemplateTaskTypes.taskType');

        // Get existing task type IDs for this production
        $existingTaskTypeIds = $production->productionTasks()
            ->where('source', 'template')
            ->pluck('production_task_type_id')
            ->toArray();

        $lastScheduledDate = null;

        foreach ($template->taskTemplateTaskTypes as $pivot) {
            $taskType = $pivot->taskType;

            // Skip if this task type already exists for this production
            if (in_array($taskType->id, $existingTaskTypeIds)) {
                continue;
            }

            $scheduledDate = $this->calculateScheduledDate(
                $production->production_date,
                $pivot->offset_days,
                $pivot->skip_weekends
            );

            if ($lastScheduledDate && $scheduledDate->lt($lastScheduledDate)) {
                $scheduledDate = $lastScheduledDate->copy();
            }

            // Use duration override if set, otherwise use task type's base duration
            $durationMinutes = $pivot->duration_override ?? $taskType->duration ?? 60;

            ProductionTask::create([
                'production_id' => $production->id,
                'task_template_item_id' => null,
                'name' => $taskType->name,
                'description' => null,
                'production_task_type_id' => $taskType->id,
                'source' => 'template',
                'sequence_order' => $pivot->sort_order,
                'scheduled_date' => $scheduledDate,
                'date' => $scheduledDate,
                'duration_minutes' => $durationMinutes,
                'is_finished' => false,
                'is_manual_schedule' => false,
                'cancelled_at' => null,
                'cancelled_reason' => null,
                'notes' => null,
            ]);

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
        // Get the template and task type pivot data for lookups
        $template = $this->getTaskTemplateForProduction($production);

        if (! $template) {
            return;
        }

        // Ensure relationships are loaded to prevent lazy loading violations
        $template->load('taskTemplateTaskTypes.taskType');

        // Build lookup of task type pivot data by task type ID
        $taskTypeLookup = $template->taskTemplateTaskTypes
            ->mapWithKeys(fn ($pivot) => [
                $pivot->taskType->id => [
                    'offset_days' => $pivot->offset_days,
                    'skip_weekends' => $pivot->skip_weekends,
                    'sort_order' => $pivot->sort_order,
                ],
            ])
            ->all();

        $tasks = $production->productionTasks
            ->whereNotNull('production_task_type_id')
            ->sortBy(fn (ProductionTask $task): array => [
                $task->sequence_order ?? PHP_INT_MAX,
                $task->id,
            ])
            ->values();

        $lastScheduledDate = null;

        foreach ($tasks as $task) {
            if ($task->is_finished || $task->isCancelled()) {
                if ($task->scheduled_date) {
                    $lastScheduledDate = Carbon::parse($task->scheduled_date);
                }

                continue;
            }

            if (! $force && $task->is_manual_schedule) {
                if ($task->scheduled_date) {
                    $lastScheduledDate = Carbon::parse($task->scheduled_date);
                }

                continue;
            }

            // Get task type pivot data from lookup
            $taskTypeData = $taskTypeLookup[$task->production_task_type_id] ?? null;

            if (! $taskTypeData) {
                if ($task->scheduled_date) {
                    $lastScheduledDate = Carbon::parse($task->scheduled_date);
                }

                continue;
            }

            $scheduledDate = $this->calculateScheduledDate(
                $production->production_date,
                $taskTypeData['offset_days'],
                $taskTypeData['skip_weekends']
            );

            if ($lastScheduledDate && $scheduledDate->lt($lastScheduledDate)) {
                $scheduledDate = $lastScheduledDate->copy();
            }

            $task->update([
                'scheduled_date' => $scheduledDate,
                'date' => $scheduledDate,
                'is_manual_schedule' => false,
            ]);

            $lastScheduledDate = $scheduledDate;
        }
    }

    /**
     * Marks a task as finished while enforcing dependency order unless bypassed.
     */
    public function markTaskAsFinished(ProductionTask $task, bool $bypassSequence = false): void
    {
        if ($task->isCancelled()) {
            throw new InvalidArgumentException('Cancelled task cannot be finished');
        }

        if ($task->is_finished) {
            return;
        }

        if (! $bypassSequence && $this->hasUnfinishedPredecessor($task)) {
            throw new InvalidArgumentException('Previous tasks must be finished first');
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
        if ($task->isCancelled()) {
            throw new InvalidArgumentException('Cancelled task cannot be finished');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reason is required to bypass dependencies');
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
     * Sets a manual date for one task and shifts following auto tasks accordingly.
     */
    public function setManualSchedule(ProductionTask $task, Carbon|string $scheduledDate): void
    {
        if ($task->is_finished || $task->isCancelled()) {
            throw new InvalidArgumentException('Finished or cancelled task cannot be rescheduled');
        }

        $previousScheduledDate = $task->scheduled_date ? Carbon::parse($task->scheduled_date) : null;
        $date = Carbon::parse($scheduledDate);

        $task->update([
            'scheduled_date' => $date,
            'date' => $date,
            'is_manual_schedule' => true,
        ]);

        if ($task->source !== 'template' || $task->sequence_order === null) {
            return;
        }

        if ($task->sequence_order === 1) {
            $production = $this->resolveProduction($task);

            if ($production) {
                $production->update([
                    'production_date' => $date->toDateString(),
                ]);
            }

            return;
        }

        if (! $previousScheduledDate) {
            return;
        }

        $deltaDays = $previousScheduledDate->diffInDays($date, false);

        if ($deltaDays === 0) {
            return;
        }

        ProductionTask::query()
            ->where('production_id', $task->production_id)
            ->where('source', 'template')
            ->where('sequence_order', '>', $task->sequence_order)
            ->where('is_finished', false)
            ->whereNull('cancelled_at')
            ->where('is_manual_schedule', false)
            ->orderBy('sequence_order')
            ->get()
            ->each(function (ProductionTask $followingTask) use ($deltaDays): void {
                if (! $followingTask->scheduled_date) {
                    return;
                }

                $newDate = Carbon::parse($followingTask->scheduled_date)->addDays($deltaDays);

                $followingTask->update([
                    'scheduled_date' => $newDate,
                    'date' => $newDate,
                ]);
            });
    }

    /**
     * Restores one task to automatic scheduling.
     */
    public function resetToAutoSchedule(ProductionTask $task): void
    {
        if (! $task->production_task_type_id || $task->is_finished || $task->isCancelled()) {
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

        $specificTemplate = TaskTemplate::where('product_type_id', $productTypeId)
            ->where('is_default', true)
            ->with('taskTemplateTaskTypes.taskType')
            ->first();

        if ($specificTemplate) {
            return $specificTemplate;
        }

        return TaskTemplate::whereNull('product_type_id')
            ->where('is_default', true)
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
