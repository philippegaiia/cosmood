<?php

namespace App\Services\Production;

use App\Enums\ProcurementStatus;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionWave;
use Illuminate\Support\Collection;

class ProcurementLineBuilder
{
    /**
     * @param  Collection<int, ProductionItem>  $items
     * @param  Collection<int|string, float>  $stockByIngredient
     * @param  Collection<int|string, float>  $reservedStockDecisions
     * @param  Collection<int, object{ordered_quantity: float, open_quantity: float, received_quantity: float}>  $contextOrderQuantities
     * @return Collection<int, object>
     */
    public function buildPlanningLines(Collection $items, Collection $stockByIngredient, Collection $reservedStockDecisions, Collection $contextOrderQuantities, ?string $needDate): Collection
    {
        return $items
            ->groupBy('ingredient_id')
            ->map(function (Collection $group, int|string $ingredientId) use ($stockByIngredient, $reservedStockDecisions, $contextOrderQuantities, $needDate): object {
                $notOrderedItems = $group->where('procurement_status', ProcurementStatus::NotOrdered);
                $orderedItems = $group->whereIn('procurement_status', [ProcurementStatus::Ordered, ProcurementStatus::Confirmed]);
                $receivedItems = $group->where('procurement_status', ProcurementStatus::Received);

                $ingredient = $group->first()?->ingredient;
                $displayUnit = $this->resolveDisplayUnit($group);
                $contextOrderSummary = $contextOrderQuantities->get((int) $ingredientId);
                $totalRequirement = (float) $group->sum(fn (ProductionItem $item): float => $this->getRequiredQuantity($item));
                $allocatedQuantity = (float) $group->sum(fn (ProductionItem $item): float => $this->getAllocatedQuantity($item));
                $remainingRequirement = max(0, $totalRequirement - $allocatedQuantity);
                $notOrderedQuantity = (float) $notOrderedItems->sum(fn (ProductionItem $item): float => $this->getRemainingQuantity($item));
                $orderedQuantity = (float) $orderedItems->sum(fn (ProductionItem $item): float => $this->getRemainingQuantity($item));
                $receivedQuantity = (float) $receivedItems->sum(fn (ProductionItem $item): float => $this->getRemainingQuantity($item));
                $requiredRemainingQuantity = $notOrderedQuantity + $orderedQuantity + $receivedQuantity;
                $ingredientPrice = (float) ($ingredient?->price ?? 0);
                $stockAdvisory = (float) ($stockByIngredient->get((int) $ingredientId) ?? 0);
                $reservedStockQuantity = min(
                    round((float) ($reservedStockDecisions->get((int) $ingredientId) ?? 0), 3),
                    round($stockAdvisory, 3),
                );
                $plannedStockQuantity = round(max(0, min($stockAdvisory - $reservedStockQuantity, $remainingRequirement)), 3);

                return (object) [
                    'ingredient_id' => (int) $ingredientId,
                    'ingredient_name' => $ingredient?->name,
                    'ingredient_price' => $ingredientPrice,
                    'display_unit' => $displayUnit,
                    'total_wave_requirement' => round($totalRequirement, 3),
                    'allocated_quantity' => round($allocatedQuantity, 3),
                    'remaining_requirement' => round($remainingRequirement, 3),
                    'wave_ordered_quantity' => round((float) ($contextOrderSummary?->ordered_quantity ?? 0), 3),
                    'wave_open_order_quantity' => round((float) ($contextOrderSummary?->open_quantity ?? 0), 3),
                    'wave_received_quantity' => round((float) ($contextOrderSummary?->received_quantity ?? 0), 3),
                    'required_remaining_quantity' => round($requiredRemainingQuantity, 3),
                    'not_ordered_quantity' => round($notOrderedQuantity, 3),
                    'ordered_quantity' => round($orderedQuantity, 3),
                    'received_quantity' => round($receivedQuantity, 3),
                    'covered_quantity' => round($orderedQuantity + $receivedQuantity, 3),
                    'to_order_quantity' => round($notOrderedQuantity, 3),
                    'available_stock' => round($stockAdvisory, 3),
                    'reserved_stock_quantity' => round($reservedStockQuantity, 3),
                    'planned_stock_quantity' => $plannedStockQuantity,
                    'wave_committed_open_orders' => 0.0,
                    'open_orders_not_committed' => 0.0,
                    'remaining_to_secure' => 0.0,
                    'remaining_to_order' => round(max(0, $remainingRequirement - $plannedStockQuantity), 3),
                    'estimated_cost' => $ingredientPrice > 0 ? round(max(0, $remainingRequirement - $plannedStockQuantity) * $ingredientPrice, 2) : null,
                    'stock_advisory' => round($stockAdvisory, 3),
                    'advisory_shortage' => round(max(0, $notOrderedQuantity - $stockAdvisory), 3),
                    'need_date' => $needDate,
                    'earliest_need_date' => $needDate,
                    'items' => $group,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, ProductionWave>  $waves
     * @param  Collection<int|string, float>  $stockByIngredient
     * @param  array<int, Collection<int, float>>  $reservedStockDecisionsByWave
     * @param  Collection<int, Collection<int, ProductionItem>>|null  $waveProductionItemsByWave
     * @param  Collection<int, Collection<int, object{ordered_quantity: float, open_quantity: float, received_quantity: float}>>|null  $waveOrderQuantitiesByWave
     * @param  callable(ProductionWave): ?string|null  $needDateResolver
     * @return Collection<int, Collection<int, object>>
     */
    public function buildWaveLines(Collection $waves, Collection $stockByIngredient, array $reservedStockDecisionsByWave = [], ?Collection $waveProductionItemsByWave = null, ?Collection $waveOrderQuantitiesByWave = null, ?callable $needDateResolver = null): Collection
    {
        $waves = $waves
            ->filter(fn (mixed $wave): bool => $wave instanceof ProductionWave)
            ->unique('id')
            ->values();

        if ($waves->isEmpty()) {
            return collect();
        }

        return $waves
            ->mapWithKeys(function (ProductionWave $wave) use ($stockByIngredient, $reservedStockDecisionsByWave, $waveProductionItemsByWave, $waveOrderQuantitiesByWave, $needDateResolver): array {
                $needDate = $needDateResolver ? $needDateResolver($wave) : null;

                return [
                    $wave->id => $this->buildPlanningLines(
                        items: $waveProductionItemsByWave?->get($wave->id, collect()) ?? collect(),
                        stockByIngredient: $stockByIngredient,
                        reservedStockDecisions: $reservedStockDecisionsByWave[$wave->id] ?? collect(),
                        contextOrderQuantities: $waveOrderQuantitiesByWave?->get($wave->id, collect()) ?? collect(),
                        needDate: $needDate,
                    ),
                ];
            });
    }

    /**
     * @param  Collection<int, Collection<int, object>>  $waveLinesByWave
     * @param  Collection<int|string, object>  $openOrderPools
     * @return array<int, array<int, float>>
     */
    public function buildPriorityProvisionalAllocations(Collection $waveLinesByWave, Collection $openOrderPools): array
    {
        $allocations = [];

        foreach ($openOrderPools as $ingredientId => $pool) {
            $sharedRemaining = (float) ($pool->shared_provisional_quantity ?? 0);
            $ingredientWaveLines = collect();

            foreach ($waveLinesByWave as $waveId => $lines) {
                $line = $lines->first(fn (object $entry): bool => (int) $entry->ingredient_id === (int) $ingredientId);

                if (! $line) {
                    continue;
                }

                $ingredientWaveLines->push((object) [
                    'wave_id' => (int) $waveId,
                    'need_date' => (string) ($line->earliest_need_date ?? '9999-12-31'),
                    'to_order_quantity' => (float) $line->to_order_quantity,
                    'committed_open_order_quantity' => (float) ($pool->commitments_by_wave->get((int) $waveId) ?? 0),
                ]);
            }

            $ingredientWaveLines = $ingredientWaveLines
                ->sortBy([
                    fn (object $entry): string => $entry->need_date,
                    fn (object $entry): int => $entry->wave_id,
                ])
                ->values();

            foreach ($ingredientWaveLines as $entry) {
                $uncoveredAfterCommitment = max(0, $entry->to_order_quantity - $entry->committed_open_order_quantity);
                $allocated = min($uncoveredAfterCommitment, $sharedRemaining);

                $allocations[$entry->wave_id][(int) $ingredientId] = round($allocated, 3);
                $sharedRemaining = max(0, $sharedRemaining - $allocated);
            }
        }

        return $allocations;
    }

    /**
     * @param  Collection<int, object>  $lines
     * @param  Collection<int|string, object>  $openOrderPools
     * @param  Collection<int|string, float>  $draftOrderQuantities
     * @param  array<int, array<int, float>>  $priorityAllocations
     * @return Collection<int, object>
     */
    public function enrichLinesWithOpenOrderContext(Collection $lines, Collection $openOrderPools, Collection $draftOrderQuantities, array $priorityAllocations, int $waveId): Collection
    {
        return $lines->map(function (object $line) use ($openOrderPools, $draftOrderQuantities, $priorityAllocations, $waveId): object {
            $ingredientId = (int) $line->ingredient_id;
            $pool = $openOrderPools->get($ingredientId);

            $openOrderQuantity = (float) ($pool->open_order_quantity ?? 0);
            $draftOpenOrderQuantity = (float) ($draftOrderQuantities->get($ingredientId) ?? 0);
            $committedOpenOrderQuantity = (float) (($pool?->commitments_by_wave?->get($waveId)) ?? 0);
            $sharedProvisionalQuantity = (float) ($pool->shared_provisional_quantity ?? 0);
            $priorityProvisionalQuantity = (float) ($priorityAllocations[$waveId][$ingredientId] ?? 0);
            $commitmentExcess = max(0, $committedOpenOrderQuantity - (float) $line->to_order_quantity);
            $waveOpenOrderQuantity = (float) ($line->wave_open_order_quantity ?? 0);
            $waveOwnUncommittedOpenQuantity = max(0, $waveOpenOrderQuantity - $committedOpenOrderQuantity);
            $otherOpenOrdersNotCommitted = max(0, $sharedProvisionalQuantity - $waveOwnUncommittedOpenQuantity);

            $line->open_order_quantity = round($openOrderQuantity, 3);
            $line->firm_open_order_quantity = round($openOrderQuantity, 3);
            $line->draft_open_order_quantity = round($draftOpenOrderQuantity, 3);
            $line->committed_open_order_quantity = round($committedOpenOrderQuantity, 3);
            $line->shared_provisional_quantity = round($sharedProvisionalQuantity, 3);
            $line->priority_provisional_quantity = round($priorityProvisionalQuantity, 3);
            $line->commitment_excess_quantity = round($commitmentExcess, 3);
            $line->available_stock = round((float) ($line->available_stock ?? $line->stock_advisory ?? 0), 3);
            $line->reserved_stock_quantity = round((float) ($line->reserved_stock_quantity ?? 0), 3);
            $line->planned_stock_quantity = round((float) ($line->planned_stock_quantity ?? $line->available_stock), 3);
            $line->wave_committed_open_orders = round($committedOpenOrderQuantity, 3);
            $line->open_orders_not_committed = round($otherOpenOrdersNotCommitted, 3);
            $line->remaining_to_secure = round(max(0, (float) ($line->remaining_requirement ?? 0) - $line->planned_stock_quantity - $waveOpenOrderQuantity), 3);
            $line->remaining_to_order = round(max(0, $line->remaining_to_secure - $otherOpenOrdersNotCommitted), 3);
            $line->estimated_cost = (float) ($line->ingredient_price ?? 0) > 0
                ? round($line->remaining_to_order * (float) $line->ingredient_price, 2)
                : null;
            $line->to_secure_quantity = round(max(0, (float) $line->to_order_quantity - $committedOpenOrderQuantity - $priorityProvisionalQuantity), 3);
            $line->advisory_shortage = round(max(0, $line->to_secure_quantity - (float) $line->stock_advisory), 3);
            $line->coverage_warning = $priorityProvisionalQuantity > 0
                ? __('Couverture partielle via pool provisoire non engagé')
                : null;

            return $line;
        });
    }

    /**
     * Production-linked purchase orders should reduce the single orphan planning gap
     * the same way wave-linked open orders reduce wave planning.
     *
     * @param  Collection<int, object>  $lines
     * @return Collection<int, object>
     */
    public function enrichProductionLinesWithLinkedOrderContext(Collection $lines): Collection
    {
        return $lines->map(function (object $line): object {
            $remainingRequirement = round((float) ($line->remaining_requirement ?? 0), 3);
            $linkedOpenOrderQuantity = round(min($remainingRequirement, (float) ($line->wave_open_order_quantity ?? 0)), 3);
            $remainingAfterLinkedOrders = round(max(0, $remainingRequirement - $linkedOpenOrderQuantity), 3);

            $line->available_stock = round((float) ($line->available_stock ?? $line->stock_advisory ?? 0), 3);
            $line->reserved_stock_quantity = round((float) ($line->reserved_stock_quantity ?? 0), 3);
            $line->planned_stock_quantity = round(min(
                (float) ($line->planned_stock_quantity ?? $line->available_stock),
                $remainingAfterLinkedOrders,
            ), 3);
            $line->remaining_after_linked_orders = $remainingAfterLinkedOrders;
            $line->wave_committed_open_orders = round($linkedOpenOrderQuantity, 3);
            $line->open_orders_not_committed = 0.0;
            $line->remaining_to_secure = round(max(
                0,
                $remainingAfterLinkedOrders - $line->planned_stock_quantity,
            ), 3);
            $line->remaining_to_order = $line->remaining_to_secure;
            $line->estimated_cost = (float) ($line->ingredient_price ?? 0) > 0
                ? round($line->remaining_to_order * (float) $line->ingredient_price, 2)
                : null;

            return $line;
        });
    }

    /**
     * @param  Collection<int, object>  $contexts
     * @param  Collection<string, Collection<int, object>>  $contextLinesByKey
     * @param  Collection<int|string, float>  $stockByIngredient
     * @return array<string, array<int, float>>
     */
    public function buildPriorityStockAllocationsForContexts(Collection $contexts, Collection $contextLinesByKey, Collection $stockByIngredient): array
    {
        $allocations = [];

        foreach ($stockByIngredient as $ingredientId => $availableStock) {
            $stockRemaining = (float) $availableStock;

            if ($stockRemaining <= 0) {
                continue;
            }

            $ingredientContexts = collect();

            foreach ($contexts as $context) {
                $line = $contextLinesByKey
                    ->get($context->context_key, collect())
                    ->first(fn (object $entry): bool => (int) $entry->ingredient_id === (int) $ingredientId);

                if (! $line) {
                    continue;
                }

                $demandAfterLinkedOrders = max(
                    0,
                    (float) ($line->remaining_requirement ?? 0) - (float) ($line->wave_open_order_quantity ?? 0),
                );
                $mobilizableDemand = min(
                    $demandAfterLinkedOrders,
                    (float) ($line->planned_stock_quantity ?? $demandAfterLinkedOrders),
                );

                $ingredientContexts->push((object) [
                    'context_key' => (string) $context->context_key,
                    'need_date' => (string) ($context->need_date ?? $line->need_date ?? '9999-12-31'),
                    'sort_order' => (int) ($context->sort_order ?? 0),
                    'demand' => $mobilizableDemand,
                ]);
            }

            $ingredientContexts = $ingredientContexts
                ->sortBy(fn (object $entry): string => sprintf('%s|%06d', $entry->need_date, $entry->sort_order))
                ->values();

            foreach ($ingredientContexts as $entry) {
                $allocated = min((float) $entry->demand, $stockRemaining);

                $allocations[$entry->context_key][(int) $ingredientId] = round($allocated, 3);
                $stockRemaining = max(0, $stockRemaining - $allocated);

                if ($stockRemaining <= 0) {
                    break;
                }
            }
        }

        return $allocations;
    }

    /**
     * @param  Collection<int, object>  $contexts
     * @param  Collection<string, Collection<int, object>>  $contextLinesByKey
     * @param  array<string, array<int, float>>  $stockAllocations
     * @param  Collection<int|string, object>  $openOrderPools
     * @return array<string, array<int, float>>
     */
    public function buildPriorityOpenOrderAllocationsForContexts(Collection $contexts, Collection $contextLinesByKey, array $stockAllocations, Collection $openOrderPools): array
    {
        $allocations = [];

        foreach ($openOrderPools as $ingredientId => $pool) {
            $sharedRemaining = (float) ($pool->shared_provisional_quantity ?? 0);

            if ($sharedRemaining <= 0) {
                continue;
            }

            $ingredientContexts = collect();

            foreach ($contexts as $context) {
                $line = $contextLinesByKey
                    ->get($context->context_key, collect())
                    ->first(fn (object $entry): bool => (int) $entry->ingredient_id === (int) $ingredientId);

                if (! $line) {
                    continue;
                }

                $stockPriorityQuantity = (float) ($stockAllocations[$context->context_key][(int) $ingredientId] ?? 0);
                $demandAfterLinkedOrdersAndStock = max(
                    0,
                    (float) ($line->remaining_requirement ?? 0)
                        - (float) ($line->wave_open_order_quantity ?? 0)
                        - $stockPriorityQuantity,
                );

                $ingredientContexts->push((object) [
                    'context_key' => (string) $context->context_key,
                    'need_date' => (string) ($context->need_date ?? $line->need_date ?? '9999-12-31'),
                    'sort_order' => (int) ($context->sort_order ?? 0),
                    'demand' => $demandAfterLinkedOrdersAndStock,
                ]);
            }

            $ingredientContexts = $ingredientContexts
                ->sortBy(fn (object $entry): string => sprintf('%s|%06d', $entry->need_date, $entry->sort_order))
                ->values();

            foreach ($ingredientContexts as $entry) {
                $allocated = min((float) $entry->demand, $sharedRemaining);

                $allocations[$entry->context_key][(int) $ingredientId] = round($allocated, 3);
                $sharedRemaining = max(0, $sharedRemaining - $allocated);

                if ($sharedRemaining <= 0) {
                    break;
                }
            }
        }

        return $allocations;
    }

    /**
     * @param  Collection<int, object>  $lines
     * @param  Collection<int|string, object>  $openOrderPools
     * @param  array<string, array<int, float>>  $stockAllocations
     * @param  array<string, array<int, float>>  $provisionalAllocations
     * @return Collection<int, object>
     */
    public function enrichContextLinesWithPlanningCoverage(Collection $lines, Collection $openOrderPools, array $stockAllocations, array $provisionalAllocations, object $context): Collection
    {
        return $lines->map(function (object $line) use ($openOrderPools, $stockAllocations, $provisionalAllocations, $context): object {
            $ingredientId = (int) $line->ingredient_id;
            $pool = $openOrderPools->get($ingredientId);
            $stockPriorityQuantity = (float) ($stockAllocations[$context->context_key][$ingredientId] ?? 0);
            $openOrdersPriorityQuantity = (float) ($provisionalAllocations[$context->context_key][$ingredientId] ?? 0);

            $line->stock_priority_quantity = round($stockPriorityQuantity, 3);
            $line->open_orders_priority_quantity = round($openOrdersPriorityQuantity, 3);
            $line->open_orders_not_committed = round((float) ($pool->shared_provisional_quantity ?? 0), 3);
            $line->remaining_to_secure = round(max(
                0,
                (float) ($line->remaining_requirement ?? 0)
                    - $stockPriorityQuantity
                    - (float) ($line->wave_open_order_quantity ?? 0),
            ), 3);
            $line->remaining_to_order = round(max(0, (float) $line->remaining_to_secure - $openOrdersPriorityQuantity), 3);
            $line->coverage_warning = $openOrdersPriorityQuantity > 0
                ? __('Couverture via PO non engagées à confirmer')
                : null;

            return $line;
        });
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return array{total_requirement_total: float, allocated_total: float, remaining_requirement_total: float, available_stock_total: float, reserved_stock_total: float, planned_stock_total: float, wave_ordered_total: float, wave_received_total: float, wave_committed_open_orders_total: float, open_orders_not_committed_total: float, remaining_to_secure_total: float, remaining_to_order_total: float, required_remaining_total: float, ordered_total: float, received_total: float, covered_total: float, firm_order_total: float, draft_order_total: float, to_order_total: float, committed_total: float, provisional_total: float, to_secure_total: float, stock_total: float, shortage_total: float, open_orders_total: float, estimated_total: float}
     */
    public function summarizePlanningLines(Collection $lines): array
    {
        return [
            'total_requirement_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->total_wave_requirement ?? 0)), 3),
            'allocated_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->allocated_quantity ?? 0)), 3),
            'remaining_requirement_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->remaining_requirement ?? 0)), 3),
            'available_stock_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->available_stock ?? 0)), 3),
            'reserved_stock_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->reserved_stock_quantity ?? 0)), 3),
            'planned_stock_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->planned_stock_quantity ?? 0)), 3),
            'wave_ordered_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->wave_ordered_quantity ?? 0)), 3),
            'wave_received_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->wave_received_quantity ?? 0)), 3),
            'wave_committed_open_orders_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->wave_committed_open_orders ?? 0)), 3),
            'open_orders_not_committed_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->open_orders_not_committed ?? 0)), 3),
            'remaining_to_secure_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->remaining_to_secure ?? 0)), 3),
            'remaining_to_order_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->remaining_to_order ?? 0)), 3),
            'required_remaining_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->required_remaining_quantity ?? 0)), 3),
            'ordered_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->ordered_quantity ?? 0)), 3),
            'received_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->received_quantity ?? 0)), 3),
            'covered_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->covered_quantity ?? 0)), 3),
            'firm_order_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->firm_open_order_quantity ?? 0)), 3),
            'draft_order_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->draft_open_order_quantity ?? 0)), 3),
            'to_order_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->to_order_quantity ?? 0)), 3),
            'committed_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->committed_open_order_quantity ?? 0)), 3),
            'provisional_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->priority_provisional_quantity ?? 0)), 3),
            'to_secure_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->to_secure_quantity ?? 0)), 3),
            'stock_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->stock_advisory ?? 0)), 3),
            'shortage_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->advisory_shortage ?? 0)), 3),
            'open_orders_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->open_order_quantity ?? 0)), 3),
            'estimated_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->estimated_cost ?? 0)), 2),
        ];
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return Collection<int, object>
     */
    public function sortPlanningLines(Collection $lines): Collection
    {
        return $lines
            ->sortBy([
                fn (object $line): float => -(float) ($line->remaining_to_order ?? 0),
                fn (object $line): float => -(float) ($line->remaining_to_secure ?? 0),
                fn (object $line): string => mb_strtolower((string) ($line->ingredient_name ?? '')),
            ])
            ->values();
    }

    public function getRemainingQuantity(ProductionItem $item): float
    {
        $required = $this->getRequiredQuantity($item);
        $allocated = $this->getAllocatedQuantity($item);

        return max(0, $required - $allocated);
    }

    public function getRequiredQuantity(ProductionItem $item): float
    {
        return (float) ($item->required_quantity > 0 ? $item->required_quantity : $item->getCalculatedQuantityKg());
    }

    /**
     * @param  Collection<int, ProductionItem>  $group
     */
    public function resolveDisplayUnit(Collection $group): string
    {
        $first = $group->first();

        if ($first?->ingredient?->base_unit?->value === 'u') {
            return 'u';
        }

        return $first?->supplierListing?->getNormalizedUnitOfMeasure() ?? 'kg';
    }

    public function getAllocatedQuantity(ProductionItem $item): float
    {
        return min($this->getRequiredQuantity($item), $item->getTotalAllocatedQuantity());
    }
}
