<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Enums\WaveStatus;
use App\Models\Production\Holiday;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionWave;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WaveProductionPlanningService
{
    private const FALLBACK_LINE_KEY = 'unassigned';

    private const RESCHEDULABLE_STATUSES = [
        ProductionStatus::Planned,
        ProductionStatus::Confirmed,
    ];

    /**
     * Builds draft production dates using per-line daily capacities.
     *
     * Existing planned/confirmed productions are counted first so new batches
     * are placed on the next truly available slot instead of failing later at
     * model-save capacity validation time.
     *
     * @param  array<int, array{production_line_id: int|null, production_id?: int|null}>  $batchPlans
     * @param  array<int, int|string>  $excludedProductionIds
     * @return array<int, Carbon>
     */
    public function planBatchDates(
        array $batchPlans,
        Carbon|string $startDate,
        bool $skipWeekends = true,
        bool $skipHolidays = true,
        int $fallbackDailyCapacity = 4,
        array $excludedProductionIds = [],
    ): array {
        if ($batchPlans === []) {
            return [];
        }

        $normalizedStartDate = $this->alignToPlanningDay(
            date: Carbon::parse($startDate)->startOfDay(),
            skipWeekends: $skipWeekends,
            skipHolidays: $skipHolidays,
        );

        $lineCapacities = $this->resolveLineCapacities($batchPlans, $fallbackDailyCapacity);
        $existingUsage = $this->resolveExistingUsage(
            batchPlans: $batchPlans,
            startDate: $normalizedStartDate,
            excludedProductionIds: $excludedProductionIds,
        );

        $lineState = [];
        $plannedDates = [];

        foreach ($batchPlans as $index => $batchPlan) {
            $lineKey = $this->resolveLineKey($batchPlan['production_line_id'] ?? null);
            $lineCapacity = $lineCapacities[$lineKey] ?? max(1, $fallbackDailyCapacity);

            if (! isset($lineState[$lineKey])) {
                $lineState[$lineKey] = [
                    'date' => $normalizedStartDate->copy(),
                ];
            }

            $planningDate = $lineState[$lineKey]['date']->copy();

            while ($this->resolveUsageCount($existingUsage, $lineKey, $planningDate) >= $lineCapacity) {
                $planningDate = $this->alignToPlanningDay(
                    date: $planningDate->copy()->addDay(),
                    skipWeekends: $skipWeekends,
                    skipHolidays: $skipHolidays,
                );
            }

            $plannedDates[$index] = $planningDate->copy();
            $this->incrementUsageCount($existingUsage, $lineKey, $planningDate);

            $lineState[$lineKey]['date'] = $planningDate->copy();

            if ($this->resolveUsageCount($existingUsage, $lineKey, $lineState[$lineKey]['date']) >= $lineCapacity) {
                $lineState[$lineKey]['date'] = $this->alignToPlanningDay(
                    date: $lineState[$lineKey]['date']->copy()->addDay(),
                    skipWeekends: $skipWeekends,
                    skipHolidays: $skipHolidays,
                );
            }
        }

        return $plannedDates;
    }

    /**
     * Recomputes planned production dates for one wave.
     *
     * @return array{planned_count: int, planned_start_date: string|null, planned_end_date: string|null}
     */
    public function rescheduleWaveProductions(
        ProductionWave $wave,
        Carbon|string $startDate,
        bool $skipWeekends = true,
        bool $skipHolidays = true,
        int $fallbackDailyCapacity = 4,
    ): array {
        if (in_array($wave->status, [WaveStatus::InProgress, WaveStatus::Completed, WaveStatus::Cancelled], true)) {
            throw new \InvalidArgumentException(__('La replanification est bloquée pour les vagues en cours, terminées ou annulées.'));
        }

        $productions = $wave->productions()
            ->orderBy('production_date')
            ->orderBy('id')
            ->get();

        $summary = $this->rescheduleProductions(
            productions: $productions,
            startDate: $startDate,
            skipWeekends: $skipWeekends,
            skipHolidays: $skipHolidays,
            fallbackDailyCapacity: $fallbackDailyCapacity,
        );

        if ((int) ($summary['rescheduled_count'] ?? 0) > 0) {
            $wave->update([
                'planned_start_date' => $summary['planned_start_date'],
                'planned_end_date' => $summary['planned_end_date'],
            ]);
        }

        return [
            'planned_count' => (int) ($summary['rescheduled_count'] ?? 0),
            'planned_start_date' => (int) ($summary['rescheduled_count'] ?? 0) > 0
                ? $summary['planned_start_date']
                : $wave->planned_start_date?->toDateString(),
            'planned_end_date' => (int) ($summary['rescheduled_count'] ?? 0) > 0
                ? $summary['planned_end_date']
                : $wave->planned_end_date?->toDateString(),
        ];
    }

    /**
     * Recomputes planned dates for selected productions.
     *
     * @param  Collection<int, Production>  $productions
     * @return array{rescheduled_count: int, skipped_count: int, planned_start_date: string|null, planned_end_date: string|null}
     */
    public function rescheduleProductions(
        Collection $productions,
        Carbon|string $startDate,
        bool $skipWeekends = true,
        bool $skipHolidays = true,
        int $fallbackDailyCapacity = 4,
    ): array {
        $reschedulableProductions = $productions
            ->filter(fn (Production $production): bool => in_array($production->status, self::RESCHEDULABLE_STATUSES, true))
            ->sortBy(fn (Production $production): string => ($production->production_date?->toDateString() ?? '9999-12-31').'-'.str_pad((string) $production->id, 10, '0', STR_PAD_LEFT))
            ->values();

        if ($reschedulableProductions->isEmpty()) {
            return [
                'rescheduled_count' => 0,
                'skipped_count' => $productions->count(),
                'planned_start_date' => null,
                'planned_end_date' => null,
            ];
        }

        $plannedDates = $this->planBatchDates(
            batchPlans: $reschedulableProductions
                ->map(fn (Production $production): array => [
                    'production_id' => $production->id,
                    'production_line_id' => $production->production_line_id,
                ])
                ->all(),
            startDate: $startDate,
            skipWeekends: $skipWeekends,
            skipHolidays: $skipHolidays,
            fallbackDailyCapacity: $fallbackDailyCapacity,
            excludedProductionIds: $reschedulableProductions->pluck('id')->all(),
        );

        $plannedStartDate = null;
        $plannedEndDate = null;

        foreach ($reschedulableProductions as $index => $production) {
            $plannedDate = $plannedDates[$index] ?? null;

            if (! $plannedDate instanceof Carbon) {
                continue;
            }

            $plannedDateString = $plannedDate->toDateString();

            if ($production->production_date?->toDateString() !== $plannedDateString) {
                $production->update([
                    'production_date' => $plannedDateString,
                ]);
            }

            $plannedStartDate ??= $plannedDateString;
            $plannedEndDate = $plannedDateString;
        }

        return [
            'rescheduled_count' => $reschedulableProductions->count(),
            'skipped_count' => $productions->count() - $reschedulableProductions->count(),
            'planned_start_date' => $plannedStartDate,
            'planned_end_date' => $plannedEndDate,
        ];
    }

    /**
     * @param  array<int, array{production_line_id: int|null, production_id?: int|null}>  $batchPlans
     * @return array<string, int>
     */
    private function resolveLineCapacities(array $batchPlans, int $fallbackDailyCapacity): array
    {
        $lineIds = collect($batchPlans)
            ->pluck('production_line_id')
            ->filter(fn (mixed $lineId): bool => (int) $lineId > 0)
            ->map(fn (mixed $lineId): int => (int) $lineId)
            ->unique()
            ->values();

        /** @var Collection<int, ProductionLine> $linesById */
        $linesById = ProductionLine::query()
            ->whereIn('id', $lineIds->all())
            ->get()
            ->keyBy('id');

        $capacities = [
            self::FALLBACK_LINE_KEY => max(1, $fallbackDailyCapacity),
        ];

        foreach ($lineIds as $lineId) {
            $capacities[(string) $lineId] = $linesById->has($lineId)
                ? $linesById->get($lineId)->resolveDailyCapacity()
                : max(1, $fallbackDailyCapacity);
        }

        return $capacities;
    }

    /**
     * @param  array<int, array{production_line_id: int|null, production_id?: int|null}>  $batchPlans
     * @param  array<int, int|string>  $excludedProductionIds
     * @return array<string, array<string, int>>
     */
    private function resolveExistingUsage(array $batchPlans, Carbon $startDate, array $excludedProductionIds = []): array
    {
        $lineIds = collect($batchPlans)
            ->pluck('production_line_id')
            ->filter(fn (mixed $lineId): bool => $lineId !== null)
            ->map(fn (mixed $lineId): int => (int) $lineId)
            ->filter(fn (int $lineId): bool => $lineId > 0)
            ->unique()
            ->values();

        $includeUnassigned = collect($batchPlans)
            ->contains(fn (array $batchPlan): bool => ! filled($batchPlan['production_line_id'] ?? null));

        $excludedIds = collect($excludedProductionIds)
            ->map(fn (mixed $productionId): int => (int) $productionId)
            ->filter(fn (int $productionId): bool => $productionId > 0)
            ->values();

        $query = Production::query()
            ->select(['id', 'production_line_id', 'production_date'])
            ->whereDate('production_date', '>=', $startDate->toDateString())
            ->whereIn('status', [
                ProductionStatus::Planned->value,
                ProductionStatus::Confirmed->value,
            ])
            ->when($lineIds->isNotEmpty() || $includeUnassigned, function ($query) use ($lineIds, $includeUnassigned): void {
                $query->where(function ($subQuery) use ($lineIds, $includeUnassigned): void {
                    if ($lineIds->isNotEmpty()) {
                        $subQuery->whereIn('production_line_id', $lineIds->all());
                    }

                    if ($includeUnassigned) {
                        $method = $lineIds->isNotEmpty() ? 'orWhereNull' : 'whereNull';
                        $subQuery->{$method}('production_line_id');
                    }
                });
            })
            ->when($excludedIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $excludedIds->all()))
            ->orderBy('production_date')
            ->orderBy('id');

        $usage = [];

        /** @var Production $production */
        foreach ($query->cursor() as $production) {
            if (! $production->production_date) {
                continue;
            }

            $lineKey = $this->resolveLineKey($production->production_line_id);
            $dateKey = $production->production_date->toDateString();

            $usage[$lineKey][$dateKey] = ($usage[$lineKey][$dateKey] ?? 0) + 1;
        }

        return $usage;
    }

    private function resolveLineKey(?int $lineId): string
    {
        if ($lineId !== null && $lineId > 0) {
            return (string) $lineId;
        }

        return self::FALLBACK_LINE_KEY;
    }

    /**
     * @param  array<string, array<string, int>>  $usage
     */
    private function resolveUsageCount(array $usage, string $lineKey, Carbon $date): int
    {
        return (int) ($usage[$lineKey][$date->toDateString()] ?? 0);
    }

    /**
     * @param  array<string, array<string, int>>  $usage
     */
    private function incrementUsageCount(array &$usage, string $lineKey, Carbon $date): void
    {
        $dateKey = $date->toDateString();

        $usage[$lineKey][$dateKey] = ($usage[$lineKey][$dateKey] ?? 0) + 1;
    }

    private function alignToPlanningDay(Carbon $date, bool $skipWeekends, bool $skipHolidays): Carbon
    {
        while (true) {
            if ($skipWeekends && $date->isWeekend()) {
                $date->addDay();

                continue;
            }

            if ($skipHolidays && Holiday::isHoliday($date)) {
                $date->addDay();

                continue;
            }

            return $date;
        }
    }
}
