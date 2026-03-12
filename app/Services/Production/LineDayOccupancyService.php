<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LineDayOccupancyService
{
    /**
     * Returns occupancy keyed by line ID then date string.
     *
     * Capacity is anchored to the production manufacturing date, not to task dates.
     * A production consumes one slot on a line/day when its production_date falls
     * on that day and the batch is still in an active planning/execution state.
     * This is intentionally batch-count based, not task-count based.
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

        $productions = $this->getCapacityProductions($normalizedLineIds, $from, $to);

        foreach ($productions->groupBy('production_line_id') as $lineId => $lineProductions) {
            foreach ($lineProductions->groupBy(fn (Production $production): string => $production->production_date->toDateString()) as $date => $datedProductions) {
                $capacity = $lines->get((int) $lineId)?->resolveDailyCapacity() ?? 1;

                $occupancy[(int) $lineId][$date] = $this->formatCell(
                    used: $datedProductions->count(),
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

        $usedSlots = $this->getCapacityProductions([$lineId], $date->copy()->startOfDay(), $date->copy()->startOfDay())
            ->when($excludeProductionId !== null, fn ($productions) => $productions->where('id', '!=', $excludeProductionId))
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
     * Fetch productions that consume one planning slot on the requested day range.
     *
     * Only active planning/execution states are included. Historical states stay
     * visible elsewhere in the board but do not reserve future capacity.
     *
     * @param  array<int, int>  $lineIds
     * @return Collection<int, Production>
     */
    private function getCapacityProductions(array $lineIds, Carbon $from, Carbon $to): Collection
    {
        return Production::query()
            ->whereIn('production_line_id', $lineIds)
            ->whereDate('production_date', '>=', $from->toDateString())
            ->whereDate('production_date', '<=', $to->toDateString())
            ->whereIn('status', [
                ProductionStatus::Planned->value,
                ProductionStatus::Confirmed->value,
                ProductionStatus::Ongoing->value,
            ])
            ->get();
    }
}
