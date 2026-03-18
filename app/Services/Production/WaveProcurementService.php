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
use App\Models\Supply\SupplierOrderItem;
use Illuminate\Support\Collection;

class WaveProcurementService
{
    private const PLANNING_PRODUCTION_STATUSES = [
        ProductionStatus::Planned,
        ProductionStatus::Confirmed,
        ProductionStatus::Ongoing,
    ];

    /**
     * @var array<string, Collection<int|string, object{open_order_quantity: float, total_committed_quantity: float, shared_provisional_quantity: float, commitments_by_wave: Collection<int, float>}>>
     */
    private array $openOrderPoolsByIngredientCache = [];

    /**
     * @var array<string, Collection<int, array{coverage: array{label: string, color: string, tooltip: string}, fabrication: array{label: string, color: string, tooltip: string}}>>
     */
    private array $waveStatusSnapshotsCache = [];

    public function __construct(
        private readonly ProcurementDataResolver $dataResolver,
        private readonly ProcurementLineBuilder $lineBuilder,
        private readonly ProcurementSignalBuilder $signalBuilder,
    ) {}

    public function aggregateRequirements(ProductionWave $wave): Collection
    {
        $items = $this->getWaveProductionItems($wave);

        return $items
            ->filter(fn (ProductionItem $item): bool => $this->lineBuilder->getRemainingQuantity($item) > 0)
            ->groupBy(fn (ProductionItem $item): string => $item->ingredient_id.'-'.$item->supplier_listing_id)
            ->map(function (Collection $group): object {
                $first = $group->first();

                return (object) [
                    'ingredient_id' => $first->ingredient_id,
                    'supplier_listing_id' => $first->supplier_listing_id,
                    'supplier_listing' => $first->supplierListing,
                    'total_quantity' => $group->sum(fn (ProductionItem $item): float => $this->lineBuilder->getRemainingQuantity($item)),
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

        $cacheKey = $this->dataResolver->getWaveIdsCacheKey($requestedWaves->keys());

        return $this->waveStatusSnapshotsCache[$cacheKey] ??= (function () use ($requestedWaves): Collection {
            $enrichedLinesByWave = $this->getEnrichedPlanningLinesByRequestedWaves($requestedWaves);

            return $requestedWaves->mapWithKeys(function (ProductionWave $wave) use ($enrichedLinesByWave): array {
                $lines = $enrichedLinesByWave->get($wave->id, collect());

                return [
                    $wave->id => [
                        'coverage' => $this->signalBuilder->buildCoverageSnapshot(
                            wave: $wave,
                            lines: $lines,
                            formatPlanningQuantityByUnit: fn (Collection $snapshotLines, string $field): string => $this->formatPlanningQuantityByUnit($snapshotLines, $field),
                        ),
                        'fabrication' => $this->signalBuilder->buildFabricationSnapshot(
                            wave: $wave,
                            lines: $lines,
                            formatPlanningQuantityByUnit: fn (Collection $snapshotLines, string $field): string => $this->formatPlanningQuantityByUnit($snapshotLines, $field),
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
        $planningWaves = $this->dataResolver->getRelevantPlanningWaves($requestedWaves);
        $stockByIngredient = $this->dataResolver->getStockByIngredient();
        $firmOpenOrderPools = $this->getOpenOrderPoolsByIngredient(OrderStatus::placedStatuses());
        $draftOrderQuantities = $this->dataResolver->getOrderQuantitiesByIngredient(OrderStatus::draftStatuses());
        $reservedStockDecisionsByWave = $this->dataResolver->getReservedStockDecisionsByWave($planningWaves);
        $waveProductionItemsByWave = $this->dataResolver->getWaveProductionItemsByWave(
            $planningWaves,
            fn (Collection $productions): Collection => $this->getPlanningItemsForProductions($productions),
        );
        $waveOrderQuantitiesByWave = $this->dataResolver->getWaveOrderQuantitiesByIngredientForWaves($planningWaves);
        $waveLinesByWave = $this->lineBuilder->buildWaveLines(
            waves: $planningWaves,
            stockByIngredient: $stockByIngredient,
            reservedStockDecisionsByWave: $reservedStockDecisionsByWave,
            waveProductionItemsByWave: $waveProductionItemsByWave,
            waveOrderQuantitiesByWave: $waveOrderQuantitiesByWave,
            needDateResolver: fn (ProductionWave $wave): ?string => $this->resolveNeedDate($wave),
        );
        $priorityAllocations = $this->lineBuilder->buildPriorityProvisionalAllocations($waveLinesByWave, $firmOpenOrderPools);

        return $planningWaves->mapWithKeys(function (ProductionWave $wave) use ($waveLinesByWave, $firmOpenOrderPools, $draftOrderQuantities, $priorityAllocations): array {
            return [
                $wave->id => $this->lineBuilder->sortPlanningLines($this->lineBuilder->enrichLinesWithOpenOrderContext(
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

        $planningWaves = $this->dataResolver->getRelevantPlanningWaves(collect([$wave]));
        $stockByIngredient = $this->dataResolver->getStockByIngredient();
        $firmOpenOrderPools = $this->getOpenOrderPoolsByIngredient(OrderStatus::placedStatuses());
        $draftOrderQuantities = $this->dataResolver->getOrderQuantitiesByIngredient(OrderStatus::draftStatuses());
        $reservedStockDecisionsByWave = $this->dataResolver->getReservedStockDecisionsByWave($planningWaves);
        $waveProductionItemsByWave = $this->dataResolver->getWaveProductionItemsByWave(
            $planningWaves,
            fn (Collection $productions): Collection => $this->getPlanningItemsForProductions($productions),
        );
        $waveOrderQuantitiesByWave = $this->dataResolver->getWaveOrderQuantitiesByIngredientForWaves($planningWaves);
        $waveLinesByWave = $this->lineBuilder->buildWaveLines(
            waves: $planningWaves,
            stockByIngredient: $stockByIngredient,
            reservedStockDecisionsByWave: $reservedStockDecisionsByWave,
            waveProductionItemsByWave: $waveProductionItemsByWave,
            waveOrderQuantitiesByWave: $waveOrderQuantitiesByWave,
            needDateResolver: fn (ProductionWave $planningWave): ?string => $this->resolveNeedDate($planningWave),
        );
        $priorityAllocations = $this->lineBuilder->buildPriorityProvisionalAllocations($waveLinesByWave, $firmOpenOrderPools);

        $waveLines = $waveLinesByWave->get($wave->id, collect());

        return $this->lineBuilder->sortPlanningLines($this->lineBuilder->enrichLinesWithOpenOrderContext(
            lines: $waveLines,
            openOrderPools: $firmOpenOrderPools,
            draftOrderQuantities: $draftOrderQuantities,
            priorityAllocations: $priorityAllocations,
            waveId: $wave->id,
        ));
    }

    public function getPlanningSummary(ProductionWave $wave): array
    {
        return $this->lineBuilder->summarizePlanningLines($this->getPlanningList($wave));
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

        $lines = $this->lineBuilder->buildPlanningLines(
            items: $this->getPlanningItemsForProductions(collect([$production])),
            stockByIngredient: $this->dataResolver->getStockByIngredient(),
            reservedStockDecisions: collect(),
            contextOrderQuantities: $this->getProductionOrderQuantitiesByIngredient($production),
            needDate: $this->resolveProductionNeedDate($production),
        );

        return $this->lineBuilder->enrichProductionLinesWithLinkedOrderContext($lines)
            ->sortBy([
                fn (object $line): float => -(float) ($line->remaining_to_order ?? 0),
                fn (object $line): float => -(float) ($line->remaining_requirement ?? 0),
                fn (object $line): string => mb_strtolower((string) ($line->ingredient_name ?? '')),
            ])
            ->values();
    }

    public function getPlanningSummaryForProduction(Production $production): array
    {
        return $this->lineBuilder->summarizePlanningLines($this->getPlanningListForProduction($production));
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

        $stockByIngredient = $this->dataResolver->getStockByIngredient();
        $firmOpenOrderPools = $this->getOpenOrderPoolsByIngredient(OrderStatus::placedStatuses());
        $draftOrderQuantities = $this->dataResolver->getOrderQuantitiesByIngredient(OrderStatus::draftStatuses());
        $reservedStockDecisionsByWave = $this->dataResolver->getReservedStockDecisionsByWave($activeWaves);
        $waveLinesByWave = $this->lineBuilder->buildWaveLines(
            waves: $activeWaves,
            stockByIngredient: $stockByIngredient,
            reservedStockDecisionsByWave: $reservedStockDecisionsByWave,
            waveProductionItemsByWave: $this->dataResolver->getWaveProductionItemsByWave(
                $activeWaves,
                fn (Collection $productions): Collection => $this->getPlanningItemsForProductions($productions),
            ),
            waveOrderQuantitiesByWave: $this->dataResolver->getWaveOrderQuantitiesByIngredientForWaves($activeWaves),
            needDateResolver: fn (ProductionWave $wave): ?string => $this->resolveNeedDate($wave),
        );
        $priorityAllocations = $this->lineBuilder->buildPriorityProvisionalAllocations($waveLinesByWave, $firmOpenOrderPools);

        $aggregated = collect();

        foreach ($activeWaves as $wave) {
            $waveLines = $this->lineBuilder->enrichLinesWithOpenOrderContext(
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
        return $this->lineBuilder->summarizePlanningLines($this->getActiveWavesPlanningList());
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

        $stockByIngredient = $this->dataResolver->getStockByIngredient();
        $firmOpenOrderPools = $this->getOpenOrderPoolsByIngredient(OrderStatus::placedStatuses());
        $reservedStockDecisionsByWave = $this->dataResolver->getReservedStockDecisionsByWave(
            $contexts
                ->pluck('wave')
                ->filter(fn (mixed $wave): bool => $wave instanceof ProductionWave)
                ->values(),
        );

        $contextLinesByKey = $contexts->mapWithKeys(function (object $context) use ($stockByIngredient, $reservedStockDecisionsByWave): array {
            return [
                $context->context_key => $this->lineBuilder->buildPlanningLines(
                    items: $this->getPlanningItemsForProductions($context->productions),
                    stockByIngredient: $stockByIngredient,
                    reservedStockDecisions: $context->wave_id !== null
                        ? ($reservedStockDecisionsByWave[$context->wave_id] ?? collect())
                        : collect(),
                    contextOrderQuantities: $context->wave_id !== null
                        ? $this->dataResolver->getWaveOrderQuantitiesByIngredient($context->wave)
                        : $this->getProductionOrderQuantitiesByIngredient($context->productions->first()),
                    needDate: $context->need_date,
                ),
            ];
        });

        $stockAllocations = $this->lineBuilder->buildPriorityStockAllocationsForContexts($contexts, $contextLinesByKey, $stockByIngredient);
        $provisionalAllocations = $this->lineBuilder->buildPriorityOpenOrderAllocationsForContexts($contexts, $contextLinesByKey, $stockAllocations, $firmOpenOrderPools);

        $aggregated = collect();

        foreach ($contexts as $context) {
            $contextLines = $this->lineBuilder->enrichContextLinesWithPlanningCoverage(
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
     * @param  array<int, OrderStatus>  $orderStatuses
     * @return Collection<int|string, object{open_order_quantity: float, total_committed_quantity: float, shared_provisional_quantity: float, commitments_by_wave: Collection<int, float>}>
     */
    private function getOpenOrderPoolsByIngredient(array $orderStatuses): Collection
    {
        $cacheKey = $this->dataResolver->getOrderStatusesCacheKey($orderStatuses);

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
                                    fn (SupplierOrderItem $item): float => $this->dataResolver->getAllocatedOrderItemQuantity($item),
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
            ...$this->dataResolver->getWavePlanningRelations(),
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
                ? Phases::normalize($production->masterbatchLot?->replaces_phase)
                : null;

            $productionItems = $production->productionItems
                ->when($replacedPhase !== null, fn ($productionItems) => $productionItems->where('phase', '!=', $replacedPhase));

            $productionItems->each(fn (ProductionItem $item) => $item->setRelation('production', $production));

            $items = $items->merge($productionItems);
        }

        return $items;
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
                $query->whereIn('order_status', OrderStatus::placedStatuses());
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
                $orderedQuantity = (float) $items->sum(fn (SupplierOrderItem $item): float => $this->dataResolver->getAllocatedOrderItemQuantity($item));
                $receivedQuantity = (float) $items->sum(fn (SupplierOrderItem $item): float => $this->dataResolver->getReceivedAllocatedOrderItemQuantity($item));
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
     * @return array<int, float>
     */
    private function getRemainingRequirementsByIngredientForProduction(Production $production): array
    {
        return $this->getPlanningItemsForProductions(collect([$production]))
            ->groupBy('ingredient_id')
            ->map(fn (Collection $items): float => round((float) $items->sum(
                fn (ProductionItem $item): float => $this->lineBuilder->getRemainingQuantity($item),
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
}
