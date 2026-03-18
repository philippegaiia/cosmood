<?php

namespace App\Services\Production;

use App\Enums\OrderStatus;
use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Enums\WaveStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductionWaveStockDecision;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use Illuminate\Support\Collection;

class WaveProcurementService
{
    private const FIRM_ORDER_STATUSES = [
        OrderStatus::Passed,
        OrderStatus::Confirmed,
        OrderStatus::Delivered,
        OrderStatus::Checked,
    ];

    private const DRAFT_ORDER_STATUSES = [
        OrderStatus::Draft,
    ];

    private const PLACED_ORDER_STATUSES = [
        OrderStatus::Passed,
        OrderStatus::Confirmed,
        OrderStatus::Delivered,
        OrderStatus::Checked,
    ];

    private const PLANNING_PRODUCTION_STATUSES = [
        ProductionStatus::Planned,
        ProductionStatus::Confirmed,
        ProductionStatus::Ongoing,
    ];

    private ?Collection $stockByIngredientCache = null;

    /**
     * @var array<string, Collection<int|string, object{open_order_quantity: float, total_committed_quantity: float, shared_provisional_quantity: float, commitments_by_wave: Collection<int, float>}>>
     */
    private array $openOrderPoolsByIngredientCache = [];

    /**
     * @var array<string, Collection<int, float>>
     */
    private array $orderQuantitiesByIngredientCache = [];

    /**
     * @var array<string, array<int, Collection<int, float>>>
     */
    private array $reservedStockDecisionsByWaveCache = [];

    /**
     * @var array<string, Collection<int, Collection<int, object{ordered_quantity: float, open_quantity: float, received_quantity: float}>>>
     */
    private array $waveOrderQuantitiesByWaveCache = [];

    /**
     * @var array<string, Collection<int, Collection<int, ProductionItem>>>
     */
    private array $waveProductionItemsByWaveCache = [];

    /**
     * @var array<string, Collection<int, array{coverage: array{label: string, color: string, tooltip: string}, fabrication: array{label: string, color: string, tooltip: string}}>>
     */
    private array $waveStatusSnapshotsCache = [];

    public function aggregateRequirements(ProductionWave $wave): Collection
    {
        $items = $this->getWaveProductionItems($wave);

        return $items
            ->filter(fn (ProductionItem $item): bool => $this->getRemainingQuantity($item) > 0)
            ->groupBy(fn (ProductionItem $item): string => $item->ingredient_id.'-'.$item->supplier_listing_id)
            ->map(function (Collection $group): object {
                $first = $group->first();

                return (object) [
                    'ingredient_id' => $first->ingredient_id,
                    'supplier_listing_id' => $first->supplier_listing_id,
                    'supplier_listing' => $first->supplierListing,
                    'total_quantity' => $group->sum(fn (ProductionItem $item): float => $this->getRemainingQuantity($item)),
                    'items' => $group,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, ProductionWave>  $visibleWaves
     * @return Collection<int, array{label: string, color: string, tooltip: string}>
     */
    public function getCoverageSnapshotForWaves(Collection $visibleWaves): Collection
    {
        return $this->getWaveStatusSnapshotsForWaves($visibleWaves)
            ->mapWithKeys(fn (array $snapshots, int $waveId): array => [$waveId => $snapshots['coverage']]);
    }

    /**
     * @param  Collection<int, ProductionWave>  $visibleWaves
     * @return Collection<int, array{label: string, color: string, tooltip: string}>
     */
    public function getFabricationSnapshotForWaves(Collection $visibleWaves): Collection
    {
        return $this->getWaveStatusSnapshotsForWaves($visibleWaves)
            ->mapWithKeys(fn (array $snapshots, int $waveId): array => [$waveId => $snapshots['fabrication']]);
    }

    /**
     * @param  Collection<int, ProductionWave>  $visibleWaves
     * @return Collection<int, array{coverage: array{label: string, color: string, tooltip: string}, fabrication: array{label: string, color: string, tooltip: string}}>
     */
    private function getWaveStatusSnapshotsForWaves(Collection $visibleWaves): Collection
    {
        $requestedWaves = $visibleWaves
            ->filter(fn (mixed $wave): bool => $wave instanceof ProductionWave && $wave->exists)
            ->mapWithKeys(fn (ProductionWave $wave): array => [$wave->id => $wave]);

        if ($requestedWaves->isEmpty()) {
            return collect();
        }

        $cacheKey = $this->getWaveIdsCacheKey($requestedWaves->keys());

        return $this->waveStatusSnapshotsCache[$cacheKey] ??= (function () use ($requestedWaves): Collection {
            $enrichedLinesByWave = $this->getEnrichedPlanningLinesByRequestedWaves($requestedWaves);

            return $requestedWaves->mapWithKeys(function (ProductionWave $wave) use ($enrichedLinesByWave): array {
                $lines = $enrichedLinesByWave->get($wave->id, collect());

                return [
                    $wave->id => [
                        'coverage' => $this->buildCoverageSnapshot(
                            wave: $wave,
                            lines: $lines,
                        ),
                        'fabrication' => $this->buildFabricationSnapshot(
                            wave: $wave,
                            lines: $lines,
                        ),
                    ],
                ];
            });
        })();
    }

    /**
     * @param  Collection<int, ProductionWave>  $requestedWaves
     * @return Collection<int, Collection<int, object>>
     */
    private function getEnrichedPlanningLinesByRequestedWaves(Collection $requestedWaves): Collection
    {
        $planningWaves = $this->getRelevantPlanningWaves($requestedWaves);
        $stockByIngredient = $this->getStockByIngredient();
        $firmOpenOrderPools = $this->getOpenOrderPoolsByIngredient(self::FIRM_ORDER_STATUSES);
        $draftOrderQuantities = $this->getOrderQuantitiesByIngredient(self::DRAFT_ORDER_STATUSES);
        $reservedStockDecisionsByWave = $this->getReservedStockDecisionsByWave($planningWaves);
        $waveProductionItemsByWave = $this->getWaveProductionItemsByWave($planningWaves);
        $waveOrderQuantitiesByWave = $this->getWaveOrderQuantitiesByIngredientForWaves($planningWaves);
        $waveLinesByWave = $this->buildWaveLines(
            waves: $planningWaves,
            stockByIngredient: $stockByIngredient,
            reservedStockDecisionsByWave: $reservedStockDecisionsByWave,
            waveProductionItemsByWave: $waveProductionItemsByWave,
            waveOrderQuantitiesByWave: $waveOrderQuantitiesByWave,
        );
        $priorityAllocations = $this->buildPriorityProvisionalAllocations($waveLinesByWave, $firmOpenOrderPools);

        return $planningWaves->mapWithKeys(function (ProductionWave $wave) use ($waveLinesByWave, $firmOpenOrderPools, $draftOrderQuantities, $priorityAllocations): array {
            return [
                $wave->id => $this->sortPlanningLines($this->enrichLinesWithOpenOrderContext(
                    lines: $waveLinesByWave->get($wave->id, collect()),
                    openOrderPools: $firmOpenOrderPools,
                    draftOrderQuantities: $draftOrderQuantities,
                    priorityAllocations: $priorityAllocations,
                    waveId: $wave->id,
                )),
            ];
        });
    }

    public function getPlanningList(ProductionWave $wave): Collection
    {
        if (! $wave->exists) {
            return collect();
        }

        $planningWaves = $this->getRelevantPlanningWaves(collect([$wave]));
        $stockByIngredient = $this->getStockByIngredient();
        $firmOpenOrderPools = $this->getOpenOrderPoolsByIngredient(self::FIRM_ORDER_STATUSES);
        $draftOrderQuantities = $this->getOrderQuantitiesByIngredient(self::DRAFT_ORDER_STATUSES);
        $reservedStockDecisionsByWave = $this->getReservedStockDecisionsByWave($planningWaves);
        $waveProductionItemsByWave = $this->getWaveProductionItemsByWave($planningWaves);
        $waveOrderQuantitiesByWave = $this->getWaveOrderQuantitiesByIngredientForWaves($planningWaves);
        $waveLinesByWave = $this->buildWaveLines(
            waves: $planningWaves,
            stockByIngredient: $stockByIngredient,
            reservedStockDecisionsByWave: $reservedStockDecisionsByWave,
            waveProductionItemsByWave: $waveProductionItemsByWave,
            waveOrderQuantitiesByWave: $waveOrderQuantitiesByWave,
        );
        $priorityAllocations = $this->buildPriorityProvisionalAllocations($waveLinesByWave, $firmOpenOrderPools);

        $waveLines = $waveLinesByWave->get($wave->id, collect());

        return $this->sortPlanningLines($this->enrichLinesWithOpenOrderContext(
            lines: $waveLines,
            openOrderPools: $firmOpenOrderPools,
            draftOrderQuantities: $draftOrderQuantities,
            priorityAllocations: $priorityAllocations,
            waveId: $wave->id,
        ));
    }

    public function getPlanningSummary(ProductionWave $wave): array
    {
        return $this->summarizePlanningLines($this->getPlanningList($wave));
    }

    public function getPlanningListForProduction(Production $production): Collection
    {
        $production = $production->fresh([
            'product:id,name',
            'productionItems.ingredient',
            'productionItems.allocations',
            'productionItems.supplierListing.supplier',
            'productionItems.supplierListing.ingredient:id,base_unit',
            'masterbatchLot',
        ]) ?? $production;

        $lines = $this->buildPlanningLinesFromItems(
            items: $this->getPlanningItemsForProductions(collect([$production])),
            stockByIngredient: $this->getStockByIngredient(),
            reservedStockDecisions: collect(),
            contextOrderQuantities: $this->getProductionOrderQuantitiesByIngredient($production),
            needDate: $this->resolveProductionNeedDate($production),
        );

        return $this->enrichProductionLinesWithLinkedOrderContext($lines)
            ->sortBy([
                fn (object $line): float => -(float) ($line->remaining_to_order ?? 0),
                fn (object $line): float => -(float) ($line->remaining_requirement ?? 0),
                fn (object $line): string => mb_strtolower((string) ($line->ingredient_name ?? '')),
            ])
            ->values();
    }

    public function getPlanningSummaryForProduction(Production $production): array
    {
        return $this->summarizePlanningLines($this->getPlanningListForProduction($production));
    }

    /**
     * @return Collection<int, float>
     */
    public function getOpenLinkedOrderQuantitiesForProduction(Production $production): Collection
    {
        return $this->getProductionOrderQuantitiesByIngredient($production)
            ->mapWithKeys(fn (object $summary, int $ingredientId): array => [$ingredientId => (float) ($summary->open_quantity ?? 0)]);
    }

    public function formatPlanningQuantity(float $quantity, string $unit): string
    {
        if ($unit === 'u') {
            return number_format(round($quantity), 0, ',', ' ').' '.$unit;
        }

        return number_format($quantity, 3, ',', ' ').' '.$unit;
    }

    /**
     * @param  Collection<int, object>  $lines
     */
    public function formatPlanningQuantityByUnit(Collection $lines, string $field): string
    {
        $formatted = $lines
            ->groupBy(fn (object $line): string => (string) ($line->display_unit ?? 'kg'))
            ->map(function (Collection $group, string $unit) use ($field): string {
                $sum = (float) $group->sum(fn (object $line): float => (float) ($line->{$field} ?? 0));

                return $this->formatPlanningQuantity($sum, $unit);
            })
            ->values()
            ->all();

        return $formatted === [] ? $this->formatPlanningQuantity(0, 'kg') : implode(' + ', $formatted);
    }

    public function getActiveWavesPlanningList(): Collection
    {
        $activeWaves = ProductionWave::query()
            ->whereIn('status', [WaveStatus::Approved, WaveStatus::InProgress])
            ->orderBy('planned_start_date')
            ->orderBy('id')
            ->get();

        if ($activeWaves->isEmpty()) {
            return collect();
        }

        $stockByIngredient = $this->getStockByIngredient();
        $firmOpenOrderPools = $this->getOpenOrderPoolsByIngredient(self::FIRM_ORDER_STATUSES);
        $draftOrderQuantities = $this->getOrderQuantitiesByIngredient(self::DRAFT_ORDER_STATUSES);

        $reservedStockDecisionsByWave = $this->getReservedStockDecisionsByWave($activeWaves);
        $waveLinesByWave = $this->buildWaveLines($activeWaves, $stockByIngredient, $reservedStockDecisionsByWave);
        $priorityAllocations = $this->buildPriorityProvisionalAllocations($waveLinesByWave, $firmOpenOrderPools);

        $aggregated = collect();

        foreach ($activeWaves as $wave) {
            $waveLines = $this->enrichLinesWithOpenOrderContext(
                lines: $waveLinesByWave->get($wave->id, collect()),
                openOrderPools: $firmOpenOrderPools,
                draftOrderQuantities: $draftOrderQuantities,
                priorityAllocations: $priorityAllocations,
                waveId: $wave->id,
            );

            foreach ($waveLines as $line) {
                $ingredientId = (int) $line->ingredient_id;

                if (! $aggregated->has($ingredientId)) {
                    $aggregated->put($ingredientId, (object) [
                        'ingredient_id' => $ingredientId,
                        'ingredient_name' => $line->ingredient_name,
                        'ingredient_price' => $line->ingredient_price,
                        'display_unit' => (string) ($line->display_unit ?? 'kg'),
                        'total_wave_requirement' => 0.0,
                        'allocated_quantity' => 0.0,
                        'remaining_requirement' => 0.0,
                        'wave_ordered_quantity' => 0.0,
                        'wave_open_order_quantity' => 0.0,
                        'wave_received_quantity' => 0.0,
                        'required_remaining_quantity' => 0.0,
                        'ordered_quantity' => 0.0,
                        'received_quantity' => 0.0,
                        'covered_quantity' => 0.0,
                        'firm_order_quantity' => 0.0,
                        'draft_order_quantity' => 0.0,
                        'to_order_quantity' => 0.0,
                        'available_stock' => (float) ($stockByIngredient->get($ingredientId) ?? 0),
                        'wave_committed_open_orders' => 0.0,
                        'open_orders_not_committed' => 0.0,
                        'remaining_to_secure' => 0.0,
                        'remaining_to_order' => 0.0,
                        'committed_open_order_quantity' => 0.0,
                        'priority_provisional_quantity' => 0.0,
                        'to_secure_quantity' => 0.0,
                        'stock_advisory' => (float) ($stockByIngredient->get($ingredientId) ?? 0),
                        'open_order_quantity' => 0.0,
                        'shared_provisional_quantity' => 0.0,
                        'advisory_shortage' => 0.0,
                        'waves_count' => 0,
                        'earliest_need_date' => null,
                        'waves' => collect(),
                    ]);
                }

                $entry = $aggregated->get($ingredientId);

                $entry->total_wave_requirement += (float) ($line->total_wave_requirement ?? 0);
                $entry->allocated_quantity += (float) ($line->allocated_quantity ?? 0);
                $entry->remaining_requirement += (float) ($line->remaining_requirement ?? 0);
                $entry->wave_ordered_quantity += (float) ($line->wave_ordered_quantity ?? 0);
                $entry->wave_open_order_quantity += (float) ($line->wave_open_order_quantity ?? 0);
                $entry->wave_received_quantity += (float) ($line->wave_received_quantity ?? 0);
                $entry->required_remaining_quantity += (float) $line->required_remaining_quantity;
                $entry->ordered_quantity += (float) $line->ordered_quantity;
                $entry->received_quantity += (float) ($line->received_quantity ?? 0);
                $entry->covered_quantity += (float) ($line->covered_quantity ?? 0);
                $entry->firm_order_quantity += (float) ($line->firm_open_order_quantity ?? 0);
                $entry->draft_order_quantity += (float) ($line->draft_open_order_quantity ?? 0);
                $entry->to_order_quantity += (float) $line->to_order_quantity;
                $entry->wave_committed_open_orders += (float) ($line->wave_committed_open_orders ?? 0);
                $entry->open_orders_not_committed = (float) ($line->open_orders_not_committed ?? 0);
                $entry->remaining_to_secure += (float) ($line->remaining_to_secure ?? 0);
                $entry->remaining_to_order += (float) ($line->remaining_to_order ?? 0);
                $entry->committed_open_order_quantity += (float) $line->committed_open_order_quantity;
                $entry->priority_provisional_quantity += (float) $line->priority_provisional_quantity;
                $entry->to_secure_quantity += (float) $line->to_secure_quantity;
                $entry->advisory_shortage += (float) $line->advisory_shortage;
                $entry->open_order_quantity = (float) $line->open_order_quantity;
                $entry->shared_provisional_quantity = (float) $line->shared_provisional_quantity;
                $entry->waves_count += 1;

                if ($line->earliest_need_date !== null && ($entry->earliest_need_date === null || $line->earliest_need_date < $entry->earliest_need_date)) {
                    $entry->earliest_need_date = $line->earliest_need_date;
                }

                $entry->waves->push((object) [
                    'wave_id' => $wave->id,
                    'wave_name' => $wave->name,
                    'wave_status' => $wave->status?->getLabel() ?? (string) $wave->status?->value,
                    'need_date' => $line->need_date,
                    'display_unit' => (string) ($line->display_unit ?? 'kg'),
                    'total_wave_requirement' => (float) ($line->total_wave_requirement ?? 0),
                    'allocated_quantity' => (float) ($line->allocated_quantity ?? 0),
                    'remaining_requirement' => (float) ($line->remaining_requirement ?? 0),
                    'wave_ordered_quantity' => (float) ($line->wave_ordered_quantity ?? 0),
                    'wave_open_order_quantity' => (float) ($line->wave_open_order_quantity ?? 0),
                    'wave_received_quantity' => (float) ($line->wave_received_quantity ?? 0),
                    'required_remaining_quantity' => (float) $line->required_remaining_quantity,
                    'ordered_quantity' => (float) $line->ordered_quantity,
                    'received_quantity' => (float) ($line->received_quantity ?? 0),
                    'covered_quantity' => (float) ($line->covered_quantity ?? 0),
                    'to_order_quantity' => (float) $line->to_order_quantity,
                    'available_stock' => (float) ($line->available_stock ?? 0),
                    'wave_committed_open_orders' => (float) ($line->wave_committed_open_orders ?? 0),
                    'open_orders_not_committed' => (float) ($line->open_orders_not_committed ?? 0),
                    'remaining_to_secure' => (float) ($line->remaining_to_secure ?? 0),
                    'remaining_to_order' => (float) ($line->remaining_to_order ?? 0),
                    'committed_open_order_quantity' => (float) $line->committed_open_order_quantity,
                    'priority_provisional_quantity' => (float) $line->priority_provisional_quantity,
                    'to_secure_quantity' => (float) $line->to_secure_quantity,
                    'commitment_excess_quantity' => (float) $line->commitment_excess_quantity,
                    'coverage_warning' => $line->coverage_warning,
                ]);
            }
        }

        return $aggregated
            ->map(function (object $entry): object {
                $entry->display_unit = (string) ($entry->display_unit ?? 'kg');
                $entry->total_wave_requirement = round((float) $entry->total_wave_requirement, 3);
                $entry->allocated_quantity = round((float) $entry->allocated_quantity, 3);
                $entry->remaining_requirement = round((float) $entry->remaining_requirement, 3);
                $entry->wave_ordered_quantity = round((float) $entry->wave_ordered_quantity, 3);
                $entry->wave_open_order_quantity = round((float) $entry->wave_open_order_quantity, 3);
                $entry->wave_received_quantity = round((float) $entry->wave_received_quantity, 3);
                $entry->required_remaining_quantity = round((float) $entry->required_remaining_quantity, 3);
                $entry->ordered_quantity = round((float) $entry->ordered_quantity, 3);
                $entry->received_quantity = round((float) $entry->received_quantity, 3);
                $entry->covered_quantity = round((float) $entry->covered_quantity, 3);
                $entry->firm_order_quantity = round((float) $entry->firm_order_quantity, 3);
                $entry->draft_order_quantity = round((float) $entry->draft_order_quantity, 3);
                $entry->to_order_quantity = round((float) $entry->to_order_quantity, 3);
                $entry->available_stock = round((float) $entry->available_stock, 3);
                $entry->wave_committed_open_orders = round((float) $entry->wave_committed_open_orders, 3);
                $entry->open_orders_not_committed = round((float) $entry->open_orders_not_committed, 3);
                $entry->remaining_to_secure = round((float) $entry->remaining_to_secure, 3);
                $entry->remaining_to_order = round((float) $entry->remaining_to_order, 3);
                $entry->committed_open_order_quantity = round((float) $entry->committed_open_order_quantity, 3);
                $entry->priority_provisional_quantity = round((float) $entry->priority_provisional_quantity, 3);
                $entry->to_secure_quantity = round((float) $entry->to_secure_quantity, 3);
                $entry->stock_advisory = round((float) $entry->stock_advisory, 3);
                $entry->open_order_quantity = round((float) $entry->open_order_quantity, 3);
                $entry->shared_provisional_quantity = round((float) $entry->shared_provisional_quantity, 3);
                $entry->advisory_shortage = round((float) $entry->advisory_shortage, 3);
                $entry->waves = $entry->waves
                    ->sortBy(fn (object $wave): string => (string) ($wave->need_date ?? '9999-12-31'))
                    ->values();

                return $entry;
            })
            ->sortBy([
                fn (object $entry): float => -$entry->advisory_shortage,
                fn (object $entry): float => -$entry->to_secure_quantity,
                fn (object $entry): string => mb_strtolower((string) $entry->ingredient_name),
            ])
            ->values();
    }

    public function getActiveWavesPlanningSummary(): array
    {
        return $this->summarizePlanningLines($this->getActiveWavesPlanningList());
    }

    /**
     * @return Collection<int, object>
     */
    public function getOperationalPlanningList(): Collection
    {
        $contexts = $this->getOperationalPlanningContexts();

        if ($contexts->isEmpty()) {
            return collect();
        }

        $stockByIngredient = $this->getStockByIngredient();
        $firmOpenOrderPools = $this->getOpenOrderPoolsByIngredient(self::FIRM_ORDER_STATUSES);
        $reservedStockDecisionsByWave = $this->getReservedStockDecisionsByWave(
            $contexts
                ->pluck('wave')
                ->filter(fn (mixed $wave): bool => $wave instanceof ProductionWave)
                ->values(),
        );

        $contextLinesByKey = $contexts->mapWithKeys(function (object $context) use ($stockByIngredient, $reservedStockDecisionsByWave): array {
            return [
                $context->context_key => $this->buildPlanningLinesFromItems(
                    items: $this->getPlanningItemsForProductions($context->productions),
                    stockByIngredient: $stockByIngredient,
                    reservedStockDecisions: $context->wave_id !== null
                        ? ($reservedStockDecisionsByWave[$context->wave_id] ?? collect())
                        : collect(),
                    contextOrderQuantities: $context->wave_id !== null
                        ? $this->getWaveOrderQuantitiesByIngredient($context->wave)
                        : $this->getProductionOrderQuantitiesByIngredient($context->productions->first()),
                    needDate: $context->need_date,
                ),
            ];
        });

        $stockAllocations = $this->buildPriorityStockAllocationsForContexts($contexts, $contextLinesByKey, $stockByIngredient);
        $provisionalAllocations = $this->buildPriorityOpenOrderAllocationsForContexts($contexts, $contextLinesByKey, $stockAllocations, $firmOpenOrderPools);

        $aggregated = collect();

        foreach ($contexts as $context) {
            $contextLines = $this->enrichContextLinesWithPlanningCoverage(
                lines: $contextLinesByKey->get($context->context_key, collect()),
                openOrderPools: $firmOpenOrderPools,
                stockAllocations: $stockAllocations,
                provisionalAllocations: $provisionalAllocations,
                context: $context,
            );

            foreach ($contextLines as $line) {
                $ingredientId = (int) $line->ingredient_id;
                $pool = $firmOpenOrderPools->get($ingredientId);

                if (! $aggregated->has($ingredientId)) {
                    $aggregated->put($ingredientId, (object) [
                        'ingredient_id' => $ingredientId,
                        'ingredient_name' => $line->ingredient_name,
                        'ingredient_price' => $line->ingredient_price,
                        'display_unit' => (string) ($line->display_unit ?? 'kg'),
                        'total_wave_requirement' => 0.0,
                        'allocated_quantity' => 0.0,
                        'remaining_requirement' => 0.0,
                        'wave_ordered_quantity' => 0.0,
                        'wave_open_order_quantity' => 0.0,
                        'wave_received_quantity' => 0.0,
                        'available_stock' => round((float) ($stockByIngredient->get($ingredientId) ?? 0), 3),
                        'open_orders_not_committed' => round((float) ($pool->shared_provisional_quantity ?? 0), 3),
                        'reserved_stock_quantity' => 0.0,
                        'planned_stock_quantity' => 0.0,
                        'remaining_to_secure' => 0.0,
                        'remaining_to_order' => 0.0,
                        'contexts_count' => 0,
                        'earliest_need_date' => null,
                        'contexts' => collect(),
                    ]);
                }

                $entry = $aggregated->get($ingredientId);

                $entry->total_wave_requirement += (float) ($line->total_wave_requirement ?? 0);
                $entry->allocated_quantity += (float) ($line->allocated_quantity ?? 0);
                $entry->remaining_requirement += (float) ($line->remaining_requirement ?? 0);
                $entry->wave_ordered_quantity += (float) ($line->wave_ordered_quantity ?? 0);
                $entry->wave_open_order_quantity += (float) ($line->wave_open_order_quantity ?? 0);
                $entry->wave_received_quantity += (float) ($line->wave_received_quantity ?? 0);
                $entry->reserved_stock_quantity += (float) ($line->reserved_stock_quantity ?? 0);
                $entry->planned_stock_quantity += (float) ($line->stock_priority_quantity ?? 0);
                $entry->remaining_to_secure += (float) ($line->remaining_to_secure ?? 0);
                $entry->remaining_to_order += (float) ($line->remaining_to_order ?? 0);
                $entry->contexts_count += 1;

                if ($line->need_date !== null && ($entry->earliest_need_date === null || $line->need_date < $entry->earliest_need_date)) {
                    $entry->earliest_need_date = $line->need_date;
                }

                $entry->contexts->push((object) [
                    'context_key' => (string) $context->context_key,
                    'context_type' => (string) $context->context_type,
                    'context_label' => (string) $context->context_label,
                    'context_status' => (string) $context->context_status,
                    'need_date' => $line->need_date,
                    'display_unit' => (string) ($line->display_unit ?? 'kg'),
                    'remaining_requirement' => round((float) ($line->remaining_requirement ?? 0), 3),
                    'wave_ordered_quantity' => round((float) ($line->wave_ordered_quantity ?? 0), 3),
                    'wave_open_order_quantity' => round((float) ($line->wave_open_order_quantity ?? 0), 3),
                    'wave_received_quantity' => round((float) ($line->wave_received_quantity ?? 0), 3),
                    'stock_priority_quantity' => round((float) ($line->stock_priority_quantity ?? 0), 3),
                    'open_orders_priority_quantity' => round((float) ($line->open_orders_priority_quantity ?? 0), 3),
                    'remaining_to_secure' => round((float) ($line->remaining_to_secure ?? 0), 3),
                    'remaining_to_order' => round((float) ($line->remaining_to_order ?? 0), 3),
                ]);
            }
        }

        return $aggregated
            ->map(function (object $entry): object {
                $entry->total_wave_requirement = round((float) $entry->total_wave_requirement, 3);
                $entry->allocated_quantity = round((float) $entry->allocated_quantity, 3);
                $entry->remaining_requirement = round((float) $entry->remaining_requirement, 3);
                $entry->wave_ordered_quantity = round((float) $entry->wave_ordered_quantity, 3);
                $entry->wave_open_order_quantity = round((float) $entry->wave_open_order_quantity, 3);
                $entry->wave_received_quantity = round((float) $entry->wave_received_quantity, 3);
                $entry->reserved_stock_quantity = round((float) $entry->reserved_stock_quantity, 3);
                $entry->planned_stock_quantity = round((float) $entry->planned_stock_quantity, 3);
                $entry->remaining_to_secure = round((float) $entry->remaining_to_secure, 3);
                $entry->remaining_to_order = round((float) $entry->remaining_to_order, 3);
                $entry->contexts = $entry->contexts
                    ->sortBy(fn (object $context): string => sprintf(
                        '%s|%s',
                        (string) ($context->need_date ?? '9999-12-31'),
                        mb_strtolower((string) $context->context_label),
                    ))
                    ->values();

                return $entry;
            })
            ->sortBy([
                fn (object $entry): float => -(float) $entry->remaining_to_order,
                fn (object $entry): float => -(float) $entry->remaining_to_secure,
                fn (object $entry): string => (string) ($entry->earliest_need_date ?? '9999-12-31'),
                fn (object $entry): string => mb_strtolower((string) $entry->ingredient_name),
            ])
            ->values();
    }

    /**
     * @return array{total_requirement: string, remaining_requirement: string, available_stock: string, wave_ordered_quantity: string, wave_received_quantity: string, open_orders_not_committed: string, remaining_to_secure: string, remaining_to_order: string, ingredients_to_order: int, contexts_count: int}
     */
    public function getOperationalPlanningSummary(): array
    {
        $lines = $this->getOperationalPlanningList();

        return [
            'total_requirement' => $this->formatPlanningQuantityByUnit($lines, 'total_wave_requirement'),
            'remaining_requirement' => $this->formatPlanningQuantityByUnit($lines, 'remaining_requirement'),
            'available_stock' => $this->formatPlanningQuantityByUnit($lines, 'available_stock'),
            'wave_ordered_quantity' => $this->formatPlanningQuantityByUnit($lines, 'wave_ordered_quantity'),
            'wave_received_quantity' => $this->formatPlanningQuantityByUnit($lines, 'wave_received_quantity'),
            'open_orders_not_committed' => $this->formatPlanningQuantityByUnit($lines, 'open_orders_not_committed'),
            'remaining_to_secure' => $this->formatPlanningQuantityByUnit($lines, 'remaining_to_secure'),
            'remaining_to_order' => $this->formatPlanningQuantityByUnit($lines, 'remaining_to_order'),
            'ingredients_to_order' => $lines->filter(fn (object $line): bool => (float) ($line->remaining_to_order ?? 0) > 0)->count(),
            'contexts_count' => (int) $lines->sum(fn (object $line): int => (int) ($line->contexts_count ?? 0)),
        ];
    }

    /**
     * @param  Collection<int, ProductionItem>  $items
     * @param  Collection<int|string, float>  $stockByIngredient
     * @param  Collection<int|string, float>  $reservedStockDecisions
     * @return Collection<int, object>
     */
    private function buildPlanningLines(ProductionWave $wave, Collection $items, Collection $stockByIngredient, Collection $reservedStockDecisions, Collection $waveOrderQuantities): Collection
    {
        return $this->buildPlanningLinesFromItems(
            items: $items,
            stockByIngredient: $stockByIngredient,
            reservedStockDecisions: $reservedStockDecisions,
            contextOrderQuantities: $waveOrderQuantities,
            needDate: $this->resolveNeedDate($wave),
        );
    }

    /**
     * @param  Collection<int, ProductionItem>  $items
     * @param  Collection<int|string, float>  $stockByIngredient
     * @param  Collection<int|string, float>  $reservedStockDecisions
     * @param  Collection<int, object{ordered_quantity: float, open_quantity: float, received_quantity: float}>  $contextOrderQuantities
     * @return Collection<int, object>
     */
    private function buildPlanningLinesFromItems(Collection $items, Collection $stockByIngredient, Collection $reservedStockDecisions, Collection $contextOrderQuantities, ?string $needDate): Collection
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
     * @return Collection<int, object>
     */
    private function getOperationalPlanningContexts(): Collection
    {
        $productions = Production::query()
            ->whereIn('status', self::PLANNING_PRODUCTION_STATUSES)
            ->with([
                'wave:id,name,status,planned_start_date',
                'product:id,name',
                'productionItems.ingredient',
                'productionItems.allocations',
                'productionItems.supplierListing.supplier',
                'productionItems.supplierListing.ingredient:id,base_unit',
                'masterbatchLot',
            ])
            ->orderBy('production_date')
            ->orderBy('id')
            ->get();

        if ($productions->isEmpty()) {
            return collect();
        }

        $waveContexts = $productions
            ->filter(fn (Production $production): bool => $production->production_wave_id !== null)
            ->groupBy('production_wave_id')
            ->map(function (Collection $group, int|string $waveId): ?object {
                $wave = $group->first()?->wave;

                if (! $wave) {
                    return null;
                }

                return (object) [
                    'context_key' => 'wave:'.$waveId,
                    'context_type' => 'wave',
                    'context_label' => (string) $wave->name,
                    'context_status' => (string) ($wave->status?->getLabel() ?? $wave->status?->value ?? __('Sans statut')),
                    'need_date' => $this->resolveNeedDate($wave),
                    'wave_id' => (int) $waveId,
                    'wave' => $wave,
                    'productions' => $group->values(),
                ];
            })
            ->filter();

        $standaloneContexts = $productions
            ->filter(fn (Production $production): bool => $production->production_wave_id === null)
            ->map(function (Production $production): object {
                return (object) [
                    'context_key' => 'production:'.$production->id,
                    'context_type' => 'production',
                    'context_label' => $this->getProductionContextLabel($production),
                    'context_status' => (string) ($production->status?->getLabel() ?? $production->status?->value ?? __('Sans statut')),
                    'need_date' => $this->resolveProductionNeedDate($production),
                    'wave_id' => null,
                    'wave' => null,
                    'productions' => collect([$production]),
                ];
            });

        return $waveContexts
            ->concat($standaloneContexts)
            ->sortBy(fn (object $context): string => sprintf(
                '%s|%s',
                (string) ($context->need_date ?? '9999-12-31'),
                mb_strtolower((string) $context->context_label),
            ))
            ->values()
            ->map(function (object $context, int $index): object {
                $context->sort_order = $index;

                return $context;
            });
    }

    /**
     * @param  Collection<int, object>  $contexts
     * @param  Collection<string, Collection<int, object>>  $contextLinesByKey
     * @param  Collection<int|string, float>  $stockByIngredient
     * @return array<string, array<int, float>>
     */
    private function buildPriorityStockAllocationsForContexts(Collection $contexts, Collection $contextLinesByKey, Collection $stockByIngredient): array
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
    private function buildPriorityOpenOrderAllocationsForContexts(Collection $contexts, Collection $contextLinesByKey, array $stockAllocations, Collection $openOrderPools): array
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
    private function enrichContextLinesWithPlanningCoverage(Collection $lines, Collection $openOrderPools, array $stockAllocations, array $provisionalAllocations, object $context): Collection
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
    private function summarizePlanningLines(Collection $lines): array
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
     * @return Collection<int|string, float>
     */
    private function getStockByIngredient(): Collection
    {
        return $this->stockByIngredientCache ??= Supply::query()
            ->where('is_in_stock', true)
            ->with('supplierListing:id,ingredient_id')
            ->withSum([
                'movements as '.Supply::ALLOCATED_QUANTITY_SUM_ATTRIBUTE => fn ($query) => $query->where('movement_type', 'allocation'),
            ], 'quantity')
            ->get()
            ->groupBy(fn (Supply $supply): ?int => $supply->supplierListing?->ingredient_id)
            ->map(fn (Collection $supplies): float => (float) $supplies->sum(fn (Supply $supply): float => $supply->getAvailableQuantity()));
    }

    /**
     * @return Collection<int|string, object{open_order_quantity: float, total_committed_quantity: float, shared_provisional_quantity: float, commitments_by_wave: Collection<int, float>}>
     */
    private function getOpenOrderPoolsByIngredient(array $orderStatuses): Collection
    {
        $cacheKey = $this->getOrderStatusesCacheKey($orderStatuses);

        return $this->openOrderPoolsByIngredientCache[$cacheKey] ??= SupplierOrderItem::query()
            ->whereNull('moved_to_stock_at')
            ->whereHas('supplierOrder', function ($query) use ($orderStatuses): void {
                $query->whereIn('order_status', $orderStatuses);
            })
            ->with([
                'supplierListing:id,ingredient_id',
                'supplierOrder:id,production_wave_id,order_status',
                'allocatedToProduction:id,status,production_wave_id,masterbatch_lot_id',
                'allocatedToProduction.productionItems.allocations',
                'allocatedToProduction.masterbatchLot:id,replaces_phase',
            ])
            ->get()
            ->pipe(function (Collection $items): Collection {
                $remainingRequirementsByProduction = $this->getRemainingRequirementsByIngredientForProductions(
                    $items
                        ->pluck('allocatedToProduction')
                        ->filter(fn (mixed $production): bool => $production instanceof Production && $this->isActiveStandaloneProduction($production))
                        ->unique('id')
                        ->values(),
                );

                return $items
                    ->groupBy(fn (SupplierOrderItem $item): ?int => $item->supplierListing?->ingredient_id)
                    ->map(function (Collection $ingredientItems, int|string $ingredientId) use ($remainingRequirementsByProduction): object {
                        $openOrderQuantity = (float) $ingredientItems->sum(fn (SupplierOrderItem $item): float => $item->getOrderedQuantityKg());

                        $commitmentsByWave = $ingredientItems
                            ->filter(fn (SupplierOrderItem $item): bool => $item->supplierOrder?->production_wave_id !== null)
                            ->groupBy(fn (SupplierOrderItem $item): int => (int) $item->supplierOrder->production_wave_id)
                            ->map(fn (Collection $waveItems): float => (float) $waveItems->sum(fn (SupplierOrderItem $item): float => (float) ($item->committed_quantity_kg ?? 0)));

                        $allocatedToProductionQuantity = (float) $ingredientItems
                            ->filter(fn (SupplierOrderItem $item): bool => $this->isActiveStandaloneProduction($item->allocatedToProduction))
                            ->groupBy(fn (SupplierOrderItem $item): int => (int) $item->allocated_to_production_id)
                            ->map(function (Collection $productionItems, int $productionId) use ($remainingRequirementsByProduction, $ingredientId): float {
                                $remainingRequirement = (float) ($remainingRequirementsByProduction[$productionId][(int) $ingredientId] ?? 0);
                                $linkedOpenOrderQuantity = (float) $productionItems->sum(
                                    fn (SupplierOrderItem $item): float => $this->getAllocatedOrderItemQuantity($item),
                                );

                                return round(min($linkedOpenOrderQuantity, $remainingRequirement), 3);
                            })
                            ->sum();

                        $totalCommittedQuantity = (float) $commitmentsByWave->sum() + $allocatedToProductionQuantity;

                        return (object) [
                            'open_order_quantity' => round($openOrderQuantity, 3),
                            'total_committed_quantity' => round($totalCommittedQuantity, 3),
                            'shared_provisional_quantity' => round(max(0, $openOrderQuantity - $totalCommittedQuantity), 3),
                            'commitments_by_wave' => $commitmentsByWave,
                        ];
                    });
            });
    }

    /**
     * @param  array<int, OrderStatus>  $orderStatuses
     * @return Collection<int, float>
     */
    private function getOrderQuantitiesByIngredient(array $orderStatuses): Collection
    {
        $cacheKey = $this->getOrderStatusesCacheKey($orderStatuses);

        return $this->orderQuantitiesByIngredientCache[$cacheKey] ??= SupplierOrderItem::query()
            ->whereNull('moved_to_stock_at')
            ->whereHas('supplierOrder', function ($query) use ($orderStatuses): void {
                $query->whereIn('order_status', $orderStatuses);
            })
            ->with('supplierListing:id,ingredient_id')
            ->get()
            ->groupBy(fn (SupplierOrderItem $item): ?int => $item->supplierListing?->ingredient_id)
            ->map(fn (Collection $items): float => round((float) $items->sum(fn (SupplierOrderItem $item): float => $item->getOrderedQuantityKg()), 3))
            ->filter(fn (float $quantity, $ingredientId): bool => $ingredientId !== null && $quantity > 0)
            ->mapWithKeys(fn (float $quantity, $ingredientId): array => [(int) $ingredientId => $quantity]);
    }

    /**
     * @param  Collection<int, ProductionWave>  $waves
     * @param  Collection<int|string, float>  $stockByIngredient
     * @param  array<int, Collection<int, float>>  $reservedStockDecisionsByWave
     * @param  Collection<int, Collection<int, ProductionItem>>|null  $waveProductionItemsByWave
     * @param  Collection<int, Collection<int, object{ordered_quantity: float, open_quantity: float, received_quantity: float}>>|null  $waveOrderQuantitiesByWave
     * @return Collection<int, Collection<int, object>>
     */
    private function buildWaveLines(Collection $waves, Collection $stockByIngredient, array $reservedStockDecisionsByWave = [], ?Collection $waveProductionItemsByWave = null, ?Collection $waveOrderQuantitiesByWave = null): Collection
    {
        $waves = $waves
            ->filter(fn (mixed $wave): bool => $wave instanceof ProductionWave)
            ->unique('id')
            ->values();

        if ($waves->isEmpty()) {
            return collect();
        }

        $waveProductionItemsByWave ??= $this->getWaveProductionItemsByWave($waves);
        $waveOrderQuantitiesByWave ??= $this->getWaveOrderQuantitiesByIngredientForWaves($waves);

        return $waves
            ->mapWithKeys(function (ProductionWave $wave) use ($stockByIngredient, $reservedStockDecisionsByWave, $waveProductionItemsByWave, $waveOrderQuantitiesByWave): array {
                return [
                    $wave->id => $this->buildPlanningLines(
                        $wave,
                        $waveProductionItemsByWave->get($wave->id, collect()),
                        $stockByIngredient,
                        $reservedStockDecisionsByWave[$wave->id] ?? collect(),
                        $waveOrderQuantitiesByWave->get($wave->id, collect()),
                    ),
                ];
            });
    }

    /**
     * @param  Collection<int, Collection<int, object>>  $waveLinesByWave
     * @param  Collection<int|string, object>  $openOrderPools
     * @return array<int, array<int, float>>
     */
    private function buildPriorityProvisionalAllocations(Collection $waveLinesByWave, Collection $openOrderPools): array
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
    private function enrichLinesWithOpenOrderContext(Collection $lines, Collection $openOrderPools, Collection $draftOrderQuantities, array $priorityAllocations, int $waveId): Collection
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
     * @param  Collection<int, ProductionWave>  $waves
     * @return array<int, Collection<int, float>>
     */
    private function getReservedStockDecisionsByWave(Collection $waves): array
    {
        $waveIds = $this->extractWaveIds($waves);

        if ($waveIds->isEmpty()) {
            return [];
        }

        $cacheKey = $this->getWaveIdsCacheKey($waveIds);

        return $this->reservedStockDecisionsByWaveCache[$cacheKey] ??= ProductionWaveStockDecision::query()
            ->whereIn('production_wave_id', $waveIds->all())
            ->get()
            ->groupBy('production_wave_id')
            ->map(fn (Collection $decisions): Collection => $decisions
                ->mapWithKeys(fn (ProductionWaveStockDecision $decision): array => [(int) $decision->ingredient_id => (float) $decision->reserved_quantity]))
            ->all();
    }

    public function getProcurementSummary(ProductionWave $wave): array
    {
        $items = $this->getWaveProductionItems($wave);

        return [
            'not_ordered' => $items->where('procurement_status', ProcurementStatus::NotOrdered)->count(),
            'ordered' => $items->whereIn('procurement_status', [ProcurementStatus::Ordered, ProcurementStatus::Confirmed])->count(),
            'received' => $items->where('procurement_status', ProcurementStatus::Received)->count(),
            'total' => $items->count(),
        ];
    }

    private function getWaveProductionItems(ProductionWave $wave): Collection
    {
        $wave->loadMissing([
            ...$this->getWavePlanningRelations(),
        ]);

        return $this->getPlanningItemsForProductions($wave->productions);
    }

    /**
     * @param  Collection<int, Production>  $productions
     * @return Collection<int, ProductionItem>
     */
    private function getPlanningItemsForProductions(Collection $productions): Collection
    {
        $items = collect();

        foreach ($productions->filter(fn (Production $production): bool => $production->status !== ProductionStatus::Cancelled) as $production) {
            $replacedPhase = $production->masterbatch_lot_id
                ? $this->normalizePhase($production->masterbatchLot?->replaces_phase)
                : null;

            $productionItems = $production->productionItems
                ->when($replacedPhase !== null, fn ($items) => $items->where('phase', '!=', $replacedPhase));

            $productionItems->each(fn (ProductionItem $item) => $item->setRelation('production', $production));

            $items = $items->merge($productionItems);
        }

        return $items;
    }

    /**
     * @return Collection<int, object{ordered_quantity: float, open_quantity: float, received_quantity: float}>
     */
    private function getWaveOrderQuantitiesByIngredient(ProductionWave $wave): Collection
    {
        return $this->getWaveOrderQuantitiesByIngredientForWaves(collect([$wave]))
            ->get($wave->id, collect());
    }

    /**
     * @return Collection<int, object{ordered_quantity: float, open_quantity: float, received_quantity: float}>
     */
    private function getProductionOrderQuantitiesByIngredient(?Production $production): Collection
    {
        if (! $production) {
            return collect();
        }

        $remainingRequirementsByIngredient = $this->getRemainingRequirementsByIngredientForProduction($production);

        return SupplierOrderItem::query()
            ->where('allocated_to_production_id', $production->id)
            ->whereHas('supplierOrder', function ($query): void {
                $query->whereIn('order_status', self::PLACED_ORDER_STATUSES);
            })
            ->with([
                'supplierListing:id,ingredient_id,unit_of_measure',
                'supplierListing.ingredient:id,base_unit',
                'supply:id,supplier_order_item_id,initial_quantity,quantity_in',
            ])
            ->get()
            ->groupBy(fn (SupplierOrderItem $item): ?int => $item->supplierListing?->ingredient_id)
            ->map(function (Collection $items, int|string $ingredientId) use ($remainingRequirementsByIngredient): object {
                $remainingRequirement = (float) ($remainingRequirementsByIngredient[(int) $ingredientId] ?? 0);
                $orderedQuantity = (float) $items->sum(fn (SupplierOrderItem $item): float => $this->getAllocatedOrderItemQuantity($item));
                $receivedQuantity = (float) $items->sum(fn (SupplierOrderItem $item): float => $this->getReceivedAllocatedOrderItemQuantity($item));
                $effectiveReceivedQuantity = round(min($receivedQuantity, $remainingRequirement), 3);
                $effectiveOpenQuantity = round(min(
                    max(0, $orderedQuantity - $receivedQuantity),
                    max(0, $remainingRequirement - $effectiveReceivedQuantity),
                ), 3);

                return (object) [
                    'ordered_quantity' => round($effectiveReceivedQuantity + $effectiveOpenQuantity, 3),
                    'open_quantity' => $effectiveOpenQuantity,
                    'received_quantity' => $effectiveReceivedQuantity,
                ];
            })
            ->filter(fn (object $summary, $ingredientId): bool => $ingredientId !== null)
            ->mapWithKeys(fn (object $summary, $ingredientId): array => [(int) $ingredientId => $summary]);
    }

    private function normalizePhase(?string $phase): ?string
    {
        if ($phase === null) {
            return null;
        }

        return match ($phase) {
            'saponified_oils' => Phases::Saponification->value,
            'lye' => Phases::Lye->value,
            'additives' => Phases::Additives->value,
            default => $phase,
        };
    }

    private function getRemainingQuantity(ProductionItem $item): float
    {
        $required = $this->getRequiredQuantity($item);
        $allocated = $this->getAllocatedQuantity($item);

        return max(0, $required - $allocated);
    }

    private function getRequiredQuantity(ProductionItem $item): float
    {
        return (float) ($item->required_quantity > 0 ? $item->required_quantity : $item->getCalculatedQuantityKg());
    }

    /**
     * @param  Collection<int, ProductionItem>  $group
     */
    private function resolveDisplayUnit(Collection $group): string
    {
        $first = $group->first();

        if ($first?->ingredient?->base_unit?->value === 'u') {
            return 'u';
        }

        return $first?->supplierListing?->getNormalizedUnitOfMeasure() ?? 'kg';
    }

    private function getAllocatedQuantity(ProductionItem $item): float
    {
        return min($this->getRequiredQuantity($item), $item->getTotalAllocatedQuantity());
    }

    /**
     * @param  Collection<int, ProductionWave>  $requestedWaves
     * @return Collection<int, ProductionWave>
     */
    private function getRelevantPlanningWaves(Collection $requestedWaves): Collection
    {
        $requestedWaveIds = $this->extractWaveIds($requestedWaves);

        $query = ProductionWave::query()
            ->whereIn('status', [WaveStatus::Approved, WaveStatus::InProgress]);

        if ($requestedWaveIds->isNotEmpty()) {
            $query->orWhereIn('id', $requestedWaveIds->all());
        }

        return $query
            ->orderBy('planned_start_date')
            ->orderBy('id')
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * @param  Collection<int, ProductionWave>  $waves
     * @return Collection<int, Collection<int, ProductionItem>>
     */
    private function getWaveProductionItemsByWave(Collection $waves): Collection
    {
        $waveIds = $this->extractWaveIds($waves);

        if ($waveIds->isEmpty()) {
            return collect();
        }

        $cacheKey = $this->getWaveIdsCacheKey($waveIds);

        if (array_key_exists($cacheKey, $this->waveProductionItemsByWaveCache)) {
            return $this->waveProductionItemsByWaveCache[$cacheKey];
        }

        $loadedWaves = ProductionWave::query()
            ->whereIn('id', $waveIds->all())
            ->with($this->getWavePlanningRelations())
            ->get()
            ->keyBy('id');

        return $this->waveProductionItemsByWaveCache[$cacheKey] = $waveIds
            ->mapWithKeys(function (int $waveId) use ($loadedWaves): array {
                $wave = $loadedWaves->get($waveId);

                return [
                    $waveId => $wave instanceof ProductionWave
                        ? $this->getPlanningItemsForProductions($wave->productions)
                        : collect(),
                ];
            });
    }

    /**
     * @param  Collection<int, ProductionWave>  $waves
     * @return Collection<int, Collection<int, object{ordered_quantity: float, open_quantity: float, received_quantity: float}>>
     */
    private function getWaveOrderQuantitiesByIngredientForWaves(Collection $waves): Collection
    {
        $waveIds = $this->extractWaveIds($waves);

        if ($waveIds->isEmpty()) {
            return collect();
        }

        $cacheKey = $this->getWaveIdsCacheKey($waveIds);

        if (array_key_exists($cacheKey, $this->waveOrderQuantitiesByWaveCache)) {
            return $this->waveOrderQuantitiesByWaveCache[$cacheKey];
        }

        $items = SupplierOrderItem::query()
            ->whereHas('supplierOrder', function ($query) use ($waveIds): void {
                $query
                    ->whereIn('production_wave_id', $waveIds->all())
                    ->whereIn('order_status', self::PLACED_ORDER_STATUSES);
            })
            ->with([
                'supplierListing:id,ingredient_id,unit_of_measure',
                'supplierListing.ingredient:id,base_unit',
                'supply:id,supplier_order_item_id,initial_quantity,quantity_in',
                'supplierOrder:id,production_wave_id',
            ])
            ->get();

        $quantitiesByWave = $waveIds->mapWithKeys(function (int $waveId) use ($items): array {
            $waveItems = $items
                ->filter(fn (SupplierOrderItem $item): bool => (int) ($item->supplierOrder?->production_wave_id ?? 0) === $waveId);

            return [
                $waveId => $waveItems
                    ->groupBy(fn (SupplierOrderItem $item): ?int => $item->supplierListing?->ingredient_id)
                    ->map(function (Collection $groupedItems): object {
                        $orderedQuantity = (float) $groupedItems->sum(fn (SupplierOrderItem $item): float => $this->getOrderItemQuantity($item));
                        $receivedQuantity = (float) $groupedItems->sum(fn (SupplierOrderItem $item): float => $this->getReceivedOrderItemQuantity($item));

                        return (object) [
                            'ordered_quantity' => round($orderedQuantity, 3),
                            'open_quantity' => round(max(0, $orderedQuantity - $receivedQuantity), 3),
                            'received_quantity' => round($receivedQuantity, 3),
                        ];
                    })
                    ->filter(fn (object $summary, $ingredientId): bool => $ingredientId !== null)
                    ->mapWithKeys(fn (object $summary, $ingredientId): array => [(int) $ingredientId => $summary]),
            ];
        });

        return $this->waveOrderQuantitiesByWaveCache[$cacheKey] = $quantitiesByWave;
    }

    /**
     * @return array<int, string>
     */
    private function getWavePlanningRelations(): array
    {
        return [
            'productions.productionItems.ingredient',
            'productions.productionItems.allocations',
            'productions.productionItems.supplierListing.supplier',
            'productions.productionItems.supplierListing.ingredient:id,base_unit',
            'productions.product:id,name',
            'productions.masterbatchLot',
        ];
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return Collection<int, object>
     */
    private function sortPlanningLines(Collection $lines): Collection
    {
        return $lines
            ->sortBy([
                fn (object $line): float => -(float) ($line->remaining_to_order ?? 0),
                fn (object $line): float => -(float) ($line->remaining_to_secure ?? 0),
                fn (object $line): string => mb_strtolower((string) ($line->ingredient_name ?? '')),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return array{label: string, color: string, tooltip: string}
     */
    private function buildCoverageSnapshot(ProductionWave $wave, Collection $lines): array
    {
        if (! $this->waveHasLinkedProductions($wave)) {
            return $this->getNoProductionCoverageSnapshot();
        }

        $signal = $this->buildCoverageSignal($lines);

        return [
            'label' => $signal['label'],
            'color' => $signal['color'],
            'tooltip' => __('Besoin total: :total | Besoin restant: :remaining | Reste à sécuriser: :toSecure | Reste à commander: :toOrder', [
                'total' => $this->formatPlanningQuantityByUnit($lines, 'total_wave_requirement'),
                'remaining' => $this->formatPlanningQuantityByUnit($lines, 'remaining_requirement'),
                'toSecure' => $this->formatPlanningQuantityByUnit($lines, 'remaining_to_secure'),
                'toOrder' => $this->formatPlanningQuantityByUnit($lines, 'remaining_to_order'),
            ]),
        ];
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return array{label: string, color: string}
     */
    private function buildCoverageSignal(Collection $lines): array
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
     * Planning-facing fabrication signal for wave lists.
     *
     * This intentionally differs from production execution readiness: it answers
     * whether fabrication looks secured from a planner/purchasing perspective,
     * even when exact lots have not yet been allocated on each production.
     *
     * @param  Collection<int, object>  $lines
     * @return array{label: string, color: string, tooltip: string}
     */
    private function buildFabricationSnapshot(ProductionWave $wave, Collection $lines): array
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
                'remaining' => $this->formatPlanningQuantityByUnit($fabricationLines, 'remaining_requirement'),
                'toOrder' => $this->formatPlanningQuantityByUnit($fabricationLines, 'remaining_to_order'),
            ]),
        ];
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return array{label: string, color: string}
     */
    private function buildFabricationSignal(Collection $lines): array
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
    private function getNoProductionCoverageSnapshot(): array
    {
        return [
            'label' => __('Sans besoin'),
            'color' => 'gray',
            'tooltip' => __('Aucune production liée.'),
        ];
    }

    private function lineReliesOnNonFirmCoverage(object $line): bool
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
    private function filterFabricationLines(Collection $lines): Collection
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

    private function lineHasFabricationCoverageSupport(object $line): bool
    {
        return round((float) ($line->planned_stock_quantity ?? 0), 3) > 0
            || round((float) ($line->wave_open_order_quantity ?? 0), 3) > 0
            || round((float) ($line->open_orders_not_committed ?? 0), 3) > 0
            || round((float) ($line->ordered_quantity ?? 0), 3) > 0
            || round((float) ($line->received_quantity ?? 0), 3) > 0;
    }

    private function waveHasLinkedProductions(ProductionWave $wave): bool
    {
        if (array_key_exists('productions_count', $wave->getAttributes())) {
            return (int) ($wave->getAttribute('productions_count') ?? 0) > 0;
        }

        return $wave->productions()->exists();
    }

    /**
     * @param  Collection<int, ProductionWave>  $waves
     * @return Collection<int, int>
     */
    private function extractWaveIds(Collection $waves): Collection
    {
        return $waves
            ->filter(fn (mixed $wave): bool => $wave instanceof ProductionWave && $wave->exists)
            ->pluck('id')
            ->map(fn (mixed $waveId): int => (int) $waveId)
            ->filter(fn (int $waveId): bool => $waveId > 0)
            ->unique()
            ->sort()
            ->values();
    }

    private function getWaveIdsCacheKey(Collection $waveIds): string
    {
        return $waveIds->implode('|');
    }

    /**
     * @param  array<int, OrderStatus>  $orderStatuses
     */
    private function getOrderStatusesCacheKey(array $orderStatuses): string
    {
        return collect($orderStatuses)
            ->map(fn (OrderStatus $status): string => $status->value)
            ->implode('|');
    }

    private function resolveNeedDate(ProductionWave $wave): ?string
    {
        return $wave->planned_start_date?->copy()->subDays(7)->toDateString();
    }

    private function resolveProductionNeedDate(Production $production): ?string
    {
        return $production->production_date?->copy()->subDays(7)->toDateString();
    }

    private function getProductionContextLabel(Production $production): string
    {
        $batchNumber = filled($production->batch_number)
            ? (string) $production->batch_number
            : __('Production # :id', ['id' => $production->id]);
        $productName = $production->product?->name;

        if (filled($productName)) {
            return $batchNumber.' - '.$productName;
        }

        return $batchNumber;
    }

    /**
     * Production-linked purchase orders should reduce the single orphan planning gap
     * the same way wave-linked open orders reduce wave planning.
     *
     * @param  Collection<int, object>  $lines
     * @return Collection<int, object>
     */
    private function enrichProductionLinesWithLinkedOrderContext(Collection $lines): Collection
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

    private function getOrderItemQuantity(SupplierOrderItem $item): float
    {
        return $item->getOrderedQuantityKg();
    }

    private function getReceivedOrderItemQuantity(SupplierOrderItem $item): float
    {
        $receivedQuantity = (float) ($item->supply?->quantity_in ?? $item->supply?->initial_quantity ?? 0);

        return round(min($receivedQuantity, $this->getOrderItemQuantity($item)), 3);
    }

    private function getAllocatedOrderItemQuantity(SupplierOrderItem $item): float
    {
        $unitWeight = (float) ($item->unit_weight ?? 0);
        $unitMultiplier = $unitWeight > 0 ? $unitWeight : 1;
        $allocatedQuantity = max(0, round((float) ($item->allocated_quantity ?? 0), 3) * $unitMultiplier);

        return round(min($allocatedQuantity, $this->getOrderItemQuantity($item)), 3);
    }

    private function getReceivedAllocatedOrderItemQuantity(SupplierOrderItem $item): float
    {
        return round(min(
            $this->getReceivedOrderItemQuantity($item),
            $this->getAllocatedOrderItemQuantity($item),
        ), 3);
    }

    /**
     * @return array<int, float>
     */
    private function getRemainingRequirementsByIngredientForProduction(Production $production): array
    {
        return $this->getPlanningItemsForProductions(collect([$production]))
            ->groupBy('ingredient_id')
            ->map(fn (Collection $items): float => round((float) $items->sum(
                fn (ProductionItem $item): float => $this->getRemainingQuantity($item),
            ), 3))
            ->filter(fn (float $quantity): bool => $quantity > 0)
            ->mapWithKeys(fn (float $quantity, int|string $ingredientId): array => [(int) $ingredientId => $quantity])
            ->all();
    }

    /**
     * @param  Collection<int, Production>  $productions
     * @return array<int, array<int, float>>
     */
    private function getRemainingRequirementsByIngredientForProductions(Collection $productions): array
    {
        return $productions
            ->mapWithKeys(fn (Production $production): array => [
                $production->id => $this->getRemainingRequirementsByIngredientForProduction($production),
            ])
            ->all();
    }

    private function isActiveStandaloneProduction(?Production $production): bool
    {
        return $production instanceof Production
            && $production->production_wave_id === null
            && in_array($production->status, self::PLANNING_PRODUCTION_STATUSES, true);
    }
}
