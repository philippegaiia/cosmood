<?php

namespace App\Services\Production;

use App\Enums\OrderStatus;
use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
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

        $stockByIngredient = Supply::query()
            ->where('is_in_stock', true)
            ->with('supplierListing:id,ingredient_id')
            ->get()
            ->groupBy(fn (Supply $supply): ?int => $supply->supplierListing?->ingredient_id)
            ->map(fn (Collection $supplies): float => (float) $supplies->sum(fn (Supply $supply): float => $supply->getAvailableQuantity()));

        return $items
            ->groupBy('ingredient_id')
            ->map(function (Collection $group, int|string $ingredientId) use ($stockByIngredient): object {
                $notOrderedItems = $group->where('procurement_status', ProcurementStatus::NotOrdered);
                $orderedItems = $group->whereIn('procurement_status', [ProcurementStatus::Ordered, ProcurementStatus::Confirmed]);

                $ingredient = $group->first()?->ingredient;
                $notOrderedQuantity = (float) $notOrderedItems->sum(fn (ProductionItem $item): float => $this->getRemainingQuantity($item));
                $orderedQuantity = (float) $orderedItems->sum(fn (ProductionItem $item): float => $this->getRemainingQuantity($item));
                $ingredientPrice = (float) ($ingredient?->price ?? 0);
                $stockAdvisory = (float) ($stockByIngredient->get((int) $ingredientId) ?? 0);

                return (object) [
                    'ingredient_id' => (int) $ingredientId,
                    'ingredient_name' => $ingredient?->name,
                    'ingredient_price' => $ingredientPrice,
                    'not_ordered_quantity' => $notOrderedQuantity,
                    'ordered_quantity' => $orderedQuantity,
                    'to_order_quantity' => $notOrderedQuantity,
                    'estimated_cost' => $ingredientPrice > 0 ? round($notOrderedQuantity * $ingredientPrice, 2) : null,
                    'stock_advisory' => $stockAdvisory,
                    'advisory_shortage' => max(0, $notOrderedQuantity - $stockAdvisory),
                    'items' => $group,
                ];
            })
            ->sortByDesc('to_order_quantity')
            ->values();
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
