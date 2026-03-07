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
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WaveProcurementService
{
    private const FIRM_ORDER_STATUSES = [
        OrderStatus::Passed,
        OrderStatus::Confirmed,
        OrderStatus::Delivered,
    ];

    private const DRAFT_ORDER_STATUSES = [
        OrderStatus::Draft,
    ];

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

    public function getPlanningList(ProductionWave $wave): Collection
    {
        $stockByIngredient = $this->getStockByIngredient();
        $firmOpenOrderPools = $this->getOpenOrderPoolsByIngredient(self::FIRM_ORDER_STATUSES);
        $draftOrderQuantities = $this->getOrderQuantitiesByIngredient(self::DRAFT_ORDER_STATUSES);

        $activeWaves = ProductionWave::query()
            ->whereIn('status', [WaveStatus::Approved, WaveStatus::InProgress])
            ->orderBy('planned_start_date')
            ->orderBy('id')
            ->get();

        if (! $activeWaves->contains(fn (ProductionWave $activeWave): bool => $activeWave->id === $wave->id)) {
            $activeWaves->push($wave);
        }

        $waveLinesByWave = $this->buildWaveLines($activeWaves, $stockByIngredient);
        $priorityAllocations = $this->buildPriorityProvisionalAllocations($waveLinesByWave, $firmOpenOrderPools);

        $waveLines = $waveLinesByWave->get($wave->id, collect());

        return $this->enrichLinesWithOpenOrderContext($waveLines, $firmOpenOrderPools, $draftOrderQuantities, $priorityAllocations, $wave->id)
            ->sortByDesc('to_order_quantity')
            ->values();
    }

    public function getPlanningSummary(ProductionWave $wave): array
    {
        return $this->summarizePlanningLines($this->getPlanningList($wave));
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

        $waveLinesByWave = $this->buildWaveLines($activeWaves, $stockByIngredient);
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
                        'required_remaining_quantity' => 0.0,
                        'ordered_quantity' => 0.0,
                        'received_quantity' => 0.0,
                        'covered_quantity' => 0.0,
                        'firm_order_quantity' => 0.0,
                        'draft_order_quantity' => 0.0,
                        'to_order_quantity' => 0.0,
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

                $entry->required_remaining_quantity += (float) $line->required_remaining_quantity;
                $entry->ordered_quantity += (float) $line->ordered_quantity;
                $entry->received_quantity += (float) ($line->received_quantity ?? 0);
                $entry->covered_quantity += (float) ($line->covered_quantity ?? 0);
                $entry->firm_order_quantity += (float) ($line->firm_open_order_quantity ?? 0);
                $entry->draft_order_quantity += (float) ($line->draft_open_order_quantity ?? 0);
                $entry->to_order_quantity += (float) $line->to_order_quantity;
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
                    'need_date' => $line->earliest_need_date,
                    'required_remaining_quantity' => (float) $line->required_remaining_quantity,
                    'ordered_quantity' => (float) $line->ordered_quantity,
                    'received_quantity' => (float) ($line->received_quantity ?? 0),
                    'covered_quantity' => (float) ($line->covered_quantity ?? 0),
                    'to_order_quantity' => (float) $line->to_order_quantity,
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
                $entry->required_remaining_quantity = round((float) $entry->required_remaining_quantity, 3);
                $entry->ordered_quantity = round((float) $entry->ordered_quantity, 3);
                $entry->received_quantity = round((float) $entry->received_quantity, 3);
                $entry->covered_quantity = round((float) $entry->covered_quantity, 3);
                $entry->firm_order_quantity = round((float) $entry->firm_order_quantity, 3);
                $entry->draft_order_quantity = round((float) $entry->draft_order_quantity, 3);
                $entry->to_order_quantity = round((float) $entry->to_order_quantity, 3);
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
     * @param  Collection<int, ProductionItem>  $items
     * @param  Collection<int|string, float>  $stockByIngredient
     * @return Collection<int, object>
     */
    private function buildPlanningLines(Collection $items, Collection $stockByIngredient): Collection
    {
        return $items
            ->groupBy('ingredient_id')
            ->map(function (Collection $group, int|string $ingredientId) use ($stockByIngredient): object {
                $notOrderedItems = $group->where('procurement_status', ProcurementStatus::NotOrdered);
                $orderedItems = $group->whereIn('procurement_status', [ProcurementStatus::Ordered, ProcurementStatus::Confirmed]);
                $receivedItems = $group->where('procurement_status', ProcurementStatus::Received);

                $ingredient = $group->first()?->ingredient;
                $notOrderedQuantity = (float) $notOrderedItems->sum(fn (ProductionItem $item): float => $this->getRemainingQuantity($item));
                $orderedQuantity = (float) $orderedItems->sum(fn (ProductionItem $item): float => $this->getRemainingQuantity($item));
                $receivedQuantity = (float) $receivedItems->sum(fn (ProductionItem $item): float => $this->getRemainingQuantity($item));
                $requiredRemainingQuantity = $notOrderedQuantity + $orderedQuantity + $receivedQuantity;
                $ingredientPrice = (float) ($ingredient?->price ?? 0);
                $stockAdvisory = (float) ($stockByIngredient->get((int) $ingredientId) ?? 0);
                $earliestNeedDate = $group
                    ->map(fn (ProductionItem $item): ?string => $item->production?->production_date?->toDateString())
                    ->filter()
                    ->sort()
                    ->first();

                return (object) [
                    'ingredient_id' => (int) $ingredientId,
                    'ingredient_name' => $ingredient?->name,
                    'ingredient_price' => $ingredientPrice,
                    'required_remaining_quantity' => round($requiredRemainingQuantity, 3),
                    'not_ordered_quantity' => round($notOrderedQuantity, 3),
                    'ordered_quantity' => round($orderedQuantity, 3),
                    'received_quantity' => round($receivedQuantity, 3),
                    'covered_quantity' => round($orderedQuantity + $receivedQuantity, 3),
                    'to_order_quantity' => round($notOrderedQuantity, 3),
                    'estimated_cost' => $ingredientPrice > 0 ? round($notOrderedQuantity * $ingredientPrice, 2) : null,
                    'stock_advisory' => round($stockAdvisory, 3),
                    'advisory_shortage' => round(max(0, $notOrderedQuantity - $stockAdvisory), 3),
                    'earliest_need_date' => $earliestNeedDate,
                    'items' => $group,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return array{required_remaining_total: float, ordered_total: float, received_total: float, covered_total: float, firm_order_total: float, draft_order_total: float, to_order_total: float, committed_total: float, provisional_total: float, to_secure_total: float, stock_total: float, shortage_total: float, open_orders_total: float, estimated_total: float}
     */
    private function summarizePlanningLines(Collection $lines): array
    {
        return [
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
        return Supply::query()
            ->where('is_in_stock', true)
            ->with('supplierListing:id,ingredient_id')
            ->get()
            ->groupBy(fn (Supply $supply): ?int => $supply->supplierListing?->ingredient_id)
            ->map(fn (Collection $supplies): float => (float) $supplies->sum(fn (Supply $supply): float => $supply->getAvailableQuantity()));
    }

    /**
     * @return Collection<int|string, object{open_order_quantity: float, total_committed_quantity: float, shared_provisional_quantity: float, commitments_by_wave: Collection<int, float>}>
     */
    private function getOpenOrderPoolsByIngredient(array $orderStatuses): Collection
    {
        return SupplierOrderItem::query()
            ->whereNull('moved_to_stock_at')
            ->whereHas('supplierOrder', function ($query) use ($orderStatuses): void {
                $query->whereIn('order_status', $orderStatuses);
            })
            ->with([
                'supplierListing:id,ingredient_id',
                'supplierOrder:id,production_wave_id,order_status',
            ])
            ->get()
            ->groupBy(fn (SupplierOrderItem $item): ?int => $item->supplierListing?->ingredient_id)
            ->map(function (Collection $items): object {
                $openOrderQuantity = (float) $items->sum(fn (SupplierOrderItem $item): float => $item->getOrderedQuantityKg());

                $commitmentsByWave = $items
                    ->filter(fn (SupplierOrderItem $item): bool => $item->supplierOrder?->production_wave_id !== null)
                    ->groupBy(fn (SupplierOrderItem $item): int => (int) $item->supplierOrder->production_wave_id)
                    ->map(fn (Collection $waveItems): float => (float) $waveItems->sum(fn (SupplierOrderItem $item): float => (float) ($item->committed_quantity_kg ?? 0)));

                $totalCommittedQuantity = (float) $commitmentsByWave->sum();

                return (object) [
                    'open_order_quantity' => round($openOrderQuantity, 3),
                    'total_committed_quantity' => round($totalCommittedQuantity, 3),
                    'shared_provisional_quantity' => round(max(0, $openOrderQuantity - $totalCommittedQuantity), 3),
                    'commitments_by_wave' => $commitmentsByWave,
                ];
            });
    }

    /**
     * @param  array<int, OrderStatus>  $orderStatuses
     * @return Collection<int, float>
     */
    private function getOrderQuantitiesByIngredient(array $orderStatuses): Collection
    {
        return SupplierOrderItem::query()
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
     * @return Collection<int, Collection<int, object>>
     */
    private function buildWaveLines(Collection $waves, Collection $stockByIngredient): Collection
    {
        return $waves
            ->unique('id')
            ->mapWithKeys(function (ProductionWave $wave) use ($stockByIngredient): array {
                return [
                    $wave->id => $this->buildPlanningLines($this->getWaveProductionItems($wave), $stockByIngredient),
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

            $line->open_order_quantity = round($openOrderQuantity, 3);
            $line->firm_open_order_quantity = round($openOrderQuantity, 3);
            $line->draft_open_order_quantity = round($draftOpenOrderQuantity, 3);
            $line->committed_open_order_quantity = round($committedOpenOrderQuantity, 3);
            $line->shared_provisional_quantity = round($sharedProvisionalQuantity, 3);
            $line->priority_provisional_quantity = round($priorityProvisionalQuantity, 3);
            $line->commitment_excess_quantity = round($commitmentExcess, 3);
            $line->to_secure_quantity = round(max(0, (float) $line->to_order_quantity - $committedOpenOrderQuantity - $priorityProvisionalQuantity), 3);
            $line->advisory_shortage = round(max(0, $line->to_secure_quantity - (float) $line->stock_advisory), 3);
            $line->coverage_warning = $priorityProvisionalQuantity > 0
                ? __('Couverture partielle via pool provisoire non engagé')
                : null;

            return $line;
        });
    }

    public function generatePurchaseOrders(ProductionWave $wave): Collection
    {
        if (! $wave->isApproved()) {
            throw new \InvalidArgumentException('Wave must be approved to generate purchase orders');
        }

        $aggregated = $this->aggregateRequirements($wave)
            ->map(function (object $item): object {
                $notOrderedItems = $item->items->where('procurement_status', ProcurementStatus::NotOrdered);

                $item->to_order_quantity = (float) $notOrderedItems
                    ->sum(fn (ProductionItem $item): float => $this->getRemainingQuantity($item));

                $item->not_ordered_item_ids = $notOrderedItems->pluck('id')->values();

                return $item;
            })
            ->filter(fn (object $item): bool => $item->to_order_quantity > 0)
            ->values();

        if ($aggregated->isEmpty()) {
            return collect();
        }

        $orders = collect();

        DB::transaction(function () use ($wave, $aggregated, &$orders): void {
            $bySupplier = $aggregated
                ->filter(fn ($item) => $item->supplier_listing_id !== null && $item->supplier_listing !== null)
                ->groupBy(fn ($item) => $item->supplier_listing->supplier_id);

            foreach ($bySupplier as $supplierId => $items) {
                if (! $supplierId) {
                    continue;
                }

                $order = SupplierOrder::create([
                    'supplier_id' => $supplierId,
                    'production_wave_id' => $wave->id,
                    'serial_number' => $this->getNextSerialNumber(),
                    'order_status' => OrderStatus::Draft,
                    'order_date' => now(),
                ]);

                foreach ($items as $item) {
                    $listing = $item->supplier_listing;

                    SupplierOrderItem::create([
                        'supplier_order_id' => $order->id,
                        'supplier_listing_id' => $listing->id,
                        'unit_weight' => $listing->unit_weight,
                        'quantity' => $item->to_order_quantity,
                        'unit_price' => $listing->price,
                        'is_in_supplies' => false,
                    ]);

                    ProductionItem::query()
                        ->whereIn('id', $item->not_ordered_item_ids)
                        ->where('procurement_status', ProcurementStatus::NotOrdered)
                        ->update(['procurement_status' => ProcurementStatus::Ordered]);
                }

                $orders->push($order->load('supplier_order_items'));
            }
        });

        return $orders;
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

    protected function getNextSerialNumber(): int
    {
        $lastOrder = SupplierOrder::orderBy('id', 'desc')->first();

        return $lastOrder ? $lastOrder->serial_number + 1 : 1001;
    }

    private function getWaveProductionItems(ProductionWave $wave): Collection
    {
        $wave->loadMissing([
            'productions.productionItems.ingredient',
            'productions.productionItems.supplierListing.supplier',
            'productions.masterbatchLot',
        ]);

        $activeProductions = $wave->productions
            ->filter(fn (Production $production): bool => $production->status !== ProductionStatus::Cancelled);

        $items = collect();

        foreach ($activeProductions as $production) {
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
        $required = (float) ($item->required_quantity > 0 ? $item->required_quantity : $item->getCalculatedQuantityKg());
        $allocated = $item->getTotalAllocatedQuantity();

        return max(0, $required - $allocated);
    }
}
