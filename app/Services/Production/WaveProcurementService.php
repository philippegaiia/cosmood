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
        $items = $this->getWaveProductionItems($wave);
        $stockByIngredient = $this->getStockByIngredient();

        return $this->buildPlanningLines($items, $stockByIngredient)
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
        $openOrdersByIngredient = $this->getOpenOrdersByIngredient();

        $aggregated = collect();

        foreach ($activeWaves as $wave) {
            $lines = $this->buildPlanningLines($this->getWaveProductionItems($wave), $stockByIngredient);

            foreach ($lines as $line) {
                $ingredientId = (int) $line->ingredient_id;

                if (! $aggregated->has($ingredientId)) {
                    $aggregated->put($ingredientId, (object) [
                        'ingredient_id' => $ingredientId,
                        'ingredient_name' => $line->ingredient_name,
                        'ingredient_price' => $line->ingredient_price,
                        'required_remaining_quantity' => 0.0,
                        'ordered_quantity' => 0.0,
                        'to_order_quantity' => 0.0,
                        'stock_advisory' => (float) ($stockByIngredient->get($ingredientId) ?? 0),
                        'open_order_quantity' => (float) ($openOrdersByIngredient->get($ingredientId) ?? 0),
                        'advisory_shortage' => 0.0,
                        'waves_count' => 0,
                        'earliest_need_date' => null,
                        'waves' => collect(),
                    ]);
                }

                $entry = $aggregated->get($ingredientId);

                $entry->required_remaining_quantity += (float) $line->required_remaining_quantity;
                $entry->ordered_quantity += (float) $line->ordered_quantity;
                $entry->to_order_quantity += (float) $line->to_order_quantity;
                $entry->waves_count += 1;

                $lineNeedDate = $line->earliest_need_date;

                if ($lineNeedDate !== null && ($entry->earliest_need_date === null || $lineNeedDate < $entry->earliest_need_date)) {
                    $entry->earliest_need_date = $lineNeedDate;
                }

                $entry->waves->push((object) [
                    'wave_id' => $wave->id,
                    'wave_name' => $wave->name,
                    'wave_status' => $wave->status?->getLabel() ?? (string) $wave->status?->value,
                    'need_date' => $line->earliest_need_date,
                    'required_remaining_quantity' => (float) $line->required_remaining_quantity,
                    'ordered_quantity' => (float) $line->ordered_quantity,
                    'to_order_quantity' => (float) $line->to_order_quantity,
                ]);
            }
        }

        return $aggregated
            ->map(function (object $entry): object {
                $entry->required_remaining_quantity = round((float) $entry->required_remaining_quantity, 3);
                $entry->ordered_quantity = round((float) $entry->ordered_quantity, 3);
                $entry->to_order_quantity = round((float) $entry->to_order_quantity, 3);
                $entry->stock_advisory = round((float) $entry->stock_advisory, 3);
                $entry->open_order_quantity = round((float) $entry->open_order_quantity, 3);
                $entry->advisory_shortage = round(max(0, (float) $entry->to_order_quantity - (float) $entry->stock_advisory), 3);
                $entry->waves = $entry->waves
                    ->sortBy(fn (object $wave): string => (string) ($wave->need_date ?? '9999-12-31'))
                    ->values();

                return $entry;
            })
            ->sortBy([
                fn (object $entry): float => -$entry->advisory_shortage,
                fn (object $entry): float => -$entry->to_order_quantity,
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

                $ingredient = $group->first()?->ingredient;
                $notOrderedQuantity = (float) $notOrderedItems->sum(fn (ProductionItem $item): float => $this->getRemainingQuantity($item));
                $orderedQuantity = (float) $orderedItems->sum(fn (ProductionItem $item): float => $this->getRemainingQuantity($item));
                $requiredRemainingQuantity = $notOrderedQuantity + $orderedQuantity;
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
                    'not_ordered_quantity' => $notOrderedQuantity,
                    'ordered_quantity' => $orderedQuantity,
                    'to_order_quantity' => $notOrderedQuantity,
                    'estimated_cost' => $ingredientPrice > 0 ? round($notOrderedQuantity * $ingredientPrice, 2) : null,
                    'stock_advisory' => $stockAdvisory,
                    'advisory_shortage' => max(0, $notOrderedQuantity - $stockAdvisory),
                    'earliest_need_date' => $earliestNeedDate,
                    'items' => $group,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return array{required_remaining_total: float, ordered_total: float, to_order_total: float, stock_total: float, shortage_total: float, open_orders_total: float, estimated_total: float}
     */
    private function summarizePlanningLines(Collection $lines): array
    {
        return [
            'required_remaining_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->required_remaining_quantity ?? 0)), 3),
            'ordered_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->ordered_quantity ?? 0)), 3),
            'to_order_total' => round((float) $lines->sum(fn (object $line): float => (float) ($line->to_order_quantity ?? 0)), 3),
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
     * @return Collection<int|string, float>
     */
    private function getOpenOrdersByIngredient(): Collection
    {
        return SupplierOrderItem::query()
            ->whereNull('moved_to_stock_at')
            ->whereHas('supplierOrder', function ($query): void {
                $query->whereIn('order_status', [
                    OrderStatus::Passed,
                    OrderStatus::Confirmed,
                    OrderStatus::Delivered,
                ]);
            })
            ->with('supplierListing:id,ingredient_id')
            ->get()
            ->groupBy(fn (SupplierOrderItem $item): ?int => $item->supplierListing?->ingredient_id)
            ->map(function (Collection $items): float {
                return (float) $items->sum(function (SupplierOrderItem $item): float {
                    $quantity = (float) ($item->quantity ?? 0);
                    $unitWeight = (float) ($item->unit_weight ?? 0);
                    $unitMultiplier = $unitWeight > 0 ? $unitWeight : 1;

                    return $quantity * $unitMultiplier;
                });
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

        DB::transaction(function () use ($aggregated, &$orders): void {
            $bySupplier = $aggregated
                ->filter(fn ($item) => $item->supplier_listing_id !== null && $item->supplier_listing !== null)
                ->groupBy(fn ($item) => $item->supplier_listing->supplier_id);

            foreach ($bySupplier as $supplierId => $items) {
                if (! $supplierId) {
                    continue;
                }

                $order = SupplierOrder::create([
                    'supplier_id' => $supplierId,
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
