<?php

namespace App\Services\Production;

use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\Production\TaskTemplate;
use App\Models\User;
use Carbon\Carbon;
use InvalidArgumentException;

class TaskGenerationService
{
    public function generateFromTemplate(Production $production, TaskTemplate $template): void
    {
        $existingTaskIds = $production->productionTasks()
            ->where('source', 'template')
            ->pluck('task_template_item_id')
            ->toArray();

        $lastScheduledDate = null;

        foreach ($template->items as $item) {
            if (in_array($item->id, $existingTaskIds)) {
                continue;
            }

            $scheduledDate = $this->calculateScheduledDate(
                $production->production_date,
                $item->offset_days,
                $item->skip_weekends
            );

            if ($lastScheduledDate && $scheduledDate->lt($lastScheduledDate)) {
                $scheduledDate = $lastScheduledDate->copy();
            }

            ProductionTask::create([
                'production_id' => $production->id,
                'task_template_item_id' => $item->id,
                'name' => $item->name,
                'description' => null,
                'production_task_type_id' => null,
                'source' => 'template',
                'sequence_order' => $item->sort_order,
                'scheduled_date' => $scheduledDate,
                'date' => $scheduledDate,
                'duration_minutes' => $item->duration_minutes ?? (($item->duration_hours ?? 0) * 60),
                'is_finished' => false,
                'is_manual_schedule' => false,
                'cancelled_at' => null,
                'cancelled_reason' => null,
                'notes' => null,
            ]);

            $lastScheduledDate = $scheduledDate;
        }
    }

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

    public function rescheduleTasks(Production $production, bool $force = false): void
    {
        $production->load('productionTasks.templateItem');

        $tasks = $production->productionTasks
            ->whereNotNull('task_template_item_id')
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

            if (! $task->templateItem) {
                if ($task->scheduled_date) {
                    $lastScheduledDate = Carbon::parse($task->scheduled_date);
                }

                continue;
            }

            $scheduledDate = $this->calculateScheduledDate(
                $production->production_date,
                $task->templateItem->offset_days,
                $task->templateItem->skip_weekends
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

    public function isBlockedByDependencies(ProductionTask $task): bool
    {
        return $this->hasUnfinishedPredecessor($task);
    }

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
            $production = $task->production;

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

        $task->production->productionTasks()
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

    public function resetToAutoSchedule(ProductionTask $task): void
    {
        if (! $task->task_template_item_id || $task->is_finished || $task->isCancelled()) {
            return;
        }

        $task->update([
            'is_manual_schedule' => false,
        ]);

        $this->rescheduleTasks($task->production, false);
    }

    protected function hasUnfinishedPredecessor(ProductionTask $task): bool
    {
        if ($task->source !== 'template' || $task->sequence_order === null) {
            return false;
        }

        return $task->production->productionTasks()
            ->where('source', 'template')
            ->where('sequence_order', '<', $task->sequence_order)
            ->where('is_finished', false)
            ->exists();
    }

    public function getTaskTemplateForProduction(Production $production): ?TaskTemplate
    {
        $productTypeId = $production->product_type_id ?? $production->product?->product_type_id;

        if (! $productTypeId) {
            return null;
        }

        $specificTemplate = TaskTemplate::where('product_type_id', $productTypeId)
            ->where('is_default', true)
            ->first();

        if ($specificTemplate) {
            return $specificTemplate;
        }

        return TaskTemplate::whereNull('product_type_id')
            ->where('is_default', true)
            ->first();
    }

    public function calculateScheduledDate($startDate, int $offsetDays, bool $skipWeekends): Carbon
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

            $daysAdded++;
        }

        return $date;
    }

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
