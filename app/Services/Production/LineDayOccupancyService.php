<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionTask;
use Carbon\Carbon;

class LineDayOccupancyService
{
    /**
     * Returns occupancy keyed by line ID then date string.
     *
     * A production consumes one slot on a line/day when at least one capacity-consuming
     * task is scheduled there on that date. Multiple consuming tasks from the same
     * production on the same date still count as one slot.
     *
     * @param  array<int, int>  $lineIds
     * @return array<int, array<string, array{used: int, capacity: int, is_near_capacity: bool, is_over_capacity: bool, is_closed: bool, has_issue: bool}>>
     */
    public function getOccupancy(array $lineIds, Carbon $from, Carbon $to): array
    {
        $normalizedLineIds = collect($lineIds)
            ->map(fn (mixed $lineId): int => (int) $lineId)
            ->filter(fn (int $lineId): bool => $lineId > 0)
            ->unique()
            ->values()
            ->all();

        if ($normalizedLineIds === []) {
            return [];
        }

        $lines = ProductionLine::query()
            ->whereIn('id', $normalizedLineIds)
            ->get()
            ->keyBy('id');

        $occupancy = [];

        foreach ($normalizedLineIds as $lineId) {
            $capacity = $lines->get($lineId)?->resolveDailyCapacity() ?? 1;

            for ($date = $from->copy()->startOfDay(); $date->lte($to); $date->addDay()) {
                $occupancy[$lineId][$date->toDateString()] = $this->formatCell(used: 0, capacity: $capacity);
            }
        }

        $tasks = $this->getCapacityTasks($normalizedLineIds, $from, $to);

        $groupedProductionIds = [];

        foreach ($tasks as $task) {
            $lineId = (int) $task->production->production_line_id;
            $date = $task->scheduled_date?->toDateString();

            if ($date === null) {
                continue;
            }

            $groupedProductionIds[$lineId][$date][$task->production_id] = true;
        }

        foreach ($groupedProductionIds as $lineId => $dates) {
            foreach ($dates as $date => $productionIds) {
                $capacity = $lines->get((int) $lineId)?->resolveDailyCapacity() ?? 1;

                $occupancy[(int) $lineId][$date] = $this->formatCell(
                    used: count($productionIds),
                    capacity: $capacity,
                );
            }
        }

        return $occupancy;
    }

    public function hasCapacity(int $lineId, Carbon $date, ?int $excludeProductionId = null): bool
    {
        $line = ProductionLine::query()->find($lineId);

        if (! $line) {
            return false;
        }

        $usedSlots = $this->getCapacityTasks([$lineId], $date->copy()->startOfDay(), $date->copy()->startOfDay())
            ->when($excludeProductionId !== null, fn ($tasks) => $tasks->where('production_id', '!=', $excludeProductionId))
            ->pluck('production_id')
            ->unique()
            ->count();

        return $usedSlots < $line->resolveDailyCapacity();
    }

    /**
     * @return array{used: int, capacity: int, is_near_capacity: bool, is_over_capacity: bool, is_closed: bool, has_issue: bool}
     */
    private function formatCell(int $used, int $capacity, bool $isClosed = false): array
    {
        $isOverCapacity = $used >= $capacity;
        $isNearCapacity = $used >= (int) ceil($capacity * 0.75) && ! $isOverCapacity;

        return [
            'used' => $used,
            'capacity' => $capacity,
            'is_near_capacity' => $isNearCapacity,
            'is_over_capacity' => $isOverCapacity,
            'is_closed' => $isClosed,
            'has_issue' => $isOverCapacity || $isClosed,
        ];
    }

    /**
     * @param  array<int, int>  $lineIds
     * @return \Illuminate\Support\Collection<int, ProductionTask>
     */
    private function getCapacityTasks(array $lineIds, Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        return ProductionTask::query()
            ->with(['production', 'productionTaskType'])
            ->whereDate('scheduled_date', '>=', $from->toDateString())
            ->whereDate('scheduled_date', '<=', $to->toDateString())
            ->whereNull('cancelled_at')
            ->whereHas('production', function ($query) use ($lineIds): void {
                $query
                    ->whereIn('production_line_id', $lineIds)
                    ->whereIn('status', [
                        ProductionStatus::Planned->value,
                        ProductionStatus::Confirmed->value,
                        ProductionStatus::Ongoing->value,
                    ]);
            })
            ->whereHas('productionTaskType', fn ($query) => $query->where('is_capacity_consuming', true))
            ->get();
    }
}
