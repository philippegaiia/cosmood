<?php

namespace App\Services\Production;

use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionWave;
use Illuminate\Support\Collection;

class ProcurementSignalBuilder
{
    /**
     * @param  Collection<int, object>  $lines
     * @param  callable(Collection<int, object>, string): string  $formatPlanningQuantityByUnit
     * @return array{label: string, color: string, tooltip: string}
     */
    public function buildCoverageSnapshot(ProductionWave $wave, Collection $lines, callable $formatPlanningQuantityByUnit): array
    {
        if (! $this->waveHasLinkedProductions($wave)) {
            return $this->getNoProductionCoverageSnapshot();
        }

        $signal = $this->buildCoverageSignal($lines);

        return [
            'label' => $signal['label'],
            'color' => $signal['color'],
            'tooltip' => __('Besoin total: :total | Besoin restant: :remaining | Reste à sécuriser: :toSecure | Reste à commander: :toOrder', [
                'total' => $formatPlanningQuantityByUnit($lines, 'total_wave_requirement'),
                'remaining' => $formatPlanningQuantityByUnit($lines, 'remaining_requirement'),
                'toSecure' => $formatPlanningQuantityByUnit($lines, 'remaining_to_secure'),
                'toOrder' => $formatPlanningQuantityByUnit($lines, 'remaining_to_order'),
            ]),
        ];
    }

    /**
     * Planning-facing fabrication signal for wave lists.
     *
     * This intentionally differs from production execution readiness: it answers
     * whether fabrication looks secured from a planner/purchasing perspective,
     * even when exact lots have not yet been allocated on each production.
     *
     * @param  Collection<int, object>  $lines
     * @param  callable(Collection<int, object>, string): string  $formatPlanningQuantityByUnit
     * @return array{label: string, color: string, tooltip: string}
     */
    public function buildFabricationSnapshot(ProductionWave $wave, Collection $lines, callable $formatPlanningQuantityByUnit): array
    {
        if (! $this->waveHasLinkedProductions($wave)) {
            return $this->getNoProductionCoverageSnapshot();
        }

        $fabricationLines = $this->filterFabricationLines($lines);

        if ($fabricationLines->isEmpty()) {
            return [
                'label' => __('Prête'),
                'color' => 'success',
                'tooltip' => __('Aucun intrant fabrication bloquant. Packaging exclu de ce signal.'),
            ];
        }

        $signal = $this->buildFabricationSignal($fabricationLines);

        return [
            'label' => $signal['label'],
            'color' => $signal['color'],
            'tooltip' => __('Non alloué fabrication: :remaining | Achat supplémentaire: :toOrder | Packaging exclu.', [
                'remaining' => $formatPlanningQuantityByUnit($fabricationLines, 'remaining_requirement'),
                'toOrder' => $formatPlanningQuantityByUnit($fabricationLines, 'remaining_to_order'),
            ]),
        ];
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return array{label: string, color: string}
     */
    public function buildCoverageSignal(Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return [
                'label' => __('Sans besoin'),
                'color' => 'gray',
            ];
        }

        $hasRemainingRequirement = $lines->contains(fn (object $line): bool => (float) ($line->remaining_requirement ?? 0) > 0);
        $hasRemainingToOrder = $lines->contains(fn (object $line): bool => (float) ($line->remaining_to_order ?? 0) > 0);
        $hasPartialCoverage = $lines->contains(fn (object $line): bool => $this->lineReliesOnNonFirmCoverage($line));

        if (! $hasRemainingRequirement) {
            return [
                'label' => __('Prête'),
                'color' => 'success',
            ];
        }

        if ($hasRemainingToOrder) {
            return [
                'label' => __('À sécuriser'),
                'color' => 'danger',
            ];
        }

        if ($hasPartialCoverage) {
            return [
                'label' => __('Partielle'),
                'color' => 'warning',
            ];
        }

        return [
            'label' => __('Prête'),
            'color' => 'success',
        ];
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return array{label: string, color: string}
     */
    public function buildFabricationSignal(Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return [
                'label' => __('Prête'),
                'color' => 'success',
            ];
        }

        $hasRemainingRequirement = $lines->contains(fn (object $line): bool => (float) ($line->remaining_requirement ?? 0) > 0);
        $hasUnsecuredNeed = $lines->contains(fn (object $line): bool => (float) ($line->remaining_requirement ?? 0) > 0 && ! $this->lineHasFabricationCoverageSupport($line));

        if (! $hasRemainingRequirement) {
            return [
                'label' => __('Prête'),
                'color' => 'success',
            ];
        }

        if ($hasUnsecuredNeed) {
            return [
                'label' => __('À sécuriser'),
                'color' => 'danger',
            ];
        }

        return [
            'label' => __('Partielle'),
            'color' => 'warning',
        ];
    }

    /**
     * @return array{label: string, color: string, tooltip: string}
     */
    public function getNoProductionCoverageSnapshot(): array
    {
        return [
            'label' => __('Sans besoin'),
            'color' => 'gray',
            'tooltip' => __('Aucune production liée.'),
        ];
    }

    public function lineReliesOnNonFirmCoverage(object $line): bool
    {
        $remainingRequirement = round((float) ($line->remaining_requirement ?? 0), 3);

        if ($remainingRequirement <= 0) {
            return false;
        }

        $remainingAfterWaveOrders = max(0, $remainingRequirement - (float) ($line->wave_open_order_quantity ?? 0));

        if ($remainingAfterWaveOrders <= 0) {
            return false;
        }

        return round((float) ($line->planned_stock_quantity ?? 0), 3) > 0
            || round((float) ($line->open_orders_not_committed ?? 0), 3) > 0;
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return Collection<int, object>
     */
    public function filterFabricationLines(Collection $lines): Collection
    {
        return $lines
            ->filter(function (object $line): bool {
                $items = $line->items ?? collect();

                if (! $items instanceof Collection) {
                    $items = collect([$items]);
                }

                return $items->contains(fn (mixed $item): bool => $item instanceof ProductionItem && $item->blocksOngoingStart());
            })
            ->values();
    }

    public function lineHasFabricationCoverageSupport(object $line): bool
    {
        return round((float) ($line->planned_stock_quantity ?? 0), 3) > 0
            || round((float) ($line->wave_open_order_quantity ?? 0), 3) > 0
            || round((float) ($line->open_orders_not_committed ?? 0), 3) > 0
            || round((float) ($line->ordered_quantity ?? 0), 3) > 0
            || round((float) ($line->received_quantity ?? 0), 3) > 0;
    }

    public function waveHasLinkedProductions(ProductionWave $wave): bool
    {
        if (array_key_exists('productions_count', $wave->getAttributes())) {
            return (int) ($wave->getAttribute('productions_count') ?? 0) > 0;
        }

        return $wave->productions()->exists();
    }
}
