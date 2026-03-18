<?php

namespace App\Services\Production;

use App\Enums\OrderStatus;
use App\Enums\WaveStatus;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductionWaveStockDecision;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use Illuminate\Support\Collection;

class ProcurementDataResolver
{
    private ?Collection $stockByIngredientCache = null;

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
     * @return Collection<int|string, float>
     */
    public function getStockByIngredient(): Collection
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
     * @param  array<int, OrderStatus>  $orderStatuses
     * @return Collection<int, float>
     */
    public function getOrderQuantitiesByIngredient(array $orderStatuses): Collection
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
     * @return array<int, Collection<int, float>>
     */
    public function getReservedStockDecisionsByWave(Collection $waves): array
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

    /**
     * @param  Collection<int, ProductionWave>  $waves
     * @param  callable(Collection<int, mixed>): Collection<int, ProductionItem>  $planningItemsResolver
     * @return Collection<int, Collection<int, ProductionItem>>
     */
    public function getWaveProductionItemsByWave(Collection $waves, callable $planningItemsResolver): Collection
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
            ->mapWithKeys(function (int $waveId) use ($loadedWaves, $planningItemsResolver): array {
                $wave = $loadedWaves->get($waveId);

                return [
                    $waveId => $wave instanceof ProductionWave
                        ? $planningItemsResolver($wave->productions)
                        : collect(),
                ];
            });
    }

    /**
     * @param  Collection<int, ProductionWave>  $waves
     * @return Collection<int, Collection<int, object{ordered_quantity: float, open_quantity: float, received_quantity: float}>>
     */
    public function getWaveOrderQuantitiesByIngredientForWaves(Collection $waves): Collection
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
                    ->whereIn('order_status', OrderStatus::placedStatuses());
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
     * @return Collection<int, object{ordered_quantity: float, open_quantity: float, received_quantity: float}>
     */
    public function getWaveOrderQuantitiesByIngredient(ProductionWave $wave): Collection
    {
        return $this->getWaveOrderQuantitiesByIngredientForWaves(collect([$wave]))
            ->get($wave->id, collect());
    }

    /**
     * @param  Collection<int, ProductionWave>  $requestedWaves
     * @return Collection<int, ProductionWave>
     */
    public function getRelevantPlanningWaves(Collection $requestedWaves): Collection
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
     * @return array<int, string>
     */
    public function getWavePlanningRelations(): array
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
     * @param  Collection<int, ProductionWave>  $waves
     * @return Collection<int, int>
     */
    public function extractWaveIds(Collection $waves): Collection
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

    public function getWaveIdsCacheKey(Collection $waveIds): string
    {
        return $waveIds->implode('|');
    }

    /**
     * @param  array<int, OrderStatus>  $orderStatuses
     */
    public function getOrderStatusesCacheKey(array $orderStatuses): string
    {
        return collect($orderStatuses)
            ->map(fn (OrderStatus $status): string => $status->value)
            ->implode('|');
    }

    public function getOrderItemQuantity(SupplierOrderItem $item): float
    {
        return $item->getOrderedQuantityKg();
    }

    public function getReceivedOrderItemQuantity(SupplierOrderItem $item): float
    {
        $receivedQuantity = (float) ($item->supply?->quantity_in ?? $item->supply?->initial_quantity ?? 0);

        return round(min($receivedQuantity, $this->getOrderItemQuantity($item)), 3);
    }

    public function getAllocatedOrderItemQuantity(SupplierOrderItem $item): float
    {
        $unitWeight = (float) ($item->unit_weight ?? 0);
        $unitMultiplier = $unitWeight > 0 ? $unitWeight : 1;
        $allocatedQuantity = max(0, round((float) ($item->allocated_quantity ?? 0), 3) * $unitMultiplier);

        return round(min($allocatedQuantity, $this->getOrderItemQuantity($item)), 3);
    }

    public function getReceivedAllocatedOrderItemQuantity(SupplierOrderItem $item): float
    {
        return round(min(
            $this->getReceivedOrderItemQuantity($item),
            $this->getAllocatedOrderItemQuantity($item),
        ), 3);
    }
}
