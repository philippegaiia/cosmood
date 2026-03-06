<?php

namespace App\Services\Production;

use App\Enums\OrderStatus;
use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionWave;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use Illuminate\Support\Collection;

class WaveRequirementStatusService
{
    private const ORDER_PLACED_STATUSES = [
        OrderStatus::Passed,
        OrderStatus::Confirmed,
        OrderStatus::Delivered,
        OrderStatus::Checked,
    ];

    public function syncForWave(ProductionWave $wave): void
    {
        $items = $this->getWaveProductionItems($wave);

        if ($items->isEmpty()) {
            return;
        }

        $orderedByIngredient = $this->getOrderedQuantitiesByIngredient($wave);
        $receivedByIngredient = $this->getReceivedQuantitiesByIngredient($wave);

        $items
            ->groupBy('ingredient_id')
            ->each(function (Collection $ingredientItems, int|string $ingredientId) use ($orderedByIngredient, $receivedByIngredient): void {
                $remainingOrdered = (float) ($orderedByIngredient->get((int) $ingredientId) ?? 0);
                $remainingReceived = (float) ($receivedByIngredient->get((int) $ingredientId) ?? 0);

                foreach ($ingredientItems as $item) {
                    $requiredQuantity = (float) ($item->required_quantity > 0 ? $item->required_quantity : $item->getCalculatedQuantityKg());

                    if ($item->isFullyAllocated()) {
                        if ($item->procurement_status !== ProcurementStatus::Received) {
                            $item->update(['procurement_status' => ProcurementStatus::Received]);
                        }

                        $remainingOrdered = max(0, $remainingOrdered - $requiredQuantity);
                        $remainingReceived = max(0, $remainingReceived - $requiredQuantity);

                        continue;
                    }

                    $nextStatus = ProcurementStatus::NotOrdered;

                    if ($remainingReceived >= $requiredQuantity) {
                        $nextStatus = ProcurementStatus::Received;
                    } elseif ($remainingOrdered >= $requiredQuantity) {
                        $nextStatus = ProcurementStatus::Ordered;
                    } elseif ($item->is_order_marked) {
                        $nextStatus = ProcurementStatus::Ordered;
                    }

                    if ($item->procurement_status !== $nextStatus) {
                        $item->update(['procurement_status' => $nextStatus]);
                    }

                    $remainingOrdered = max(0, $remainingOrdered - $requiredQuantity);
                    $remainingReceived = max(0, $remainingReceived - $requiredQuantity);
                }
            });
    }

    private function getWaveProductionItems(ProductionWave $wave): Collection
    {
        $wave->loadMissing([
            'productions.productionItems.ingredient',
            'productions.productionItems.allocations',
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

        return $items->sortBy(fn (ProductionItem $item): string => ($item->production?->production_date?->format('Y-m-d') ?? '9999-12-31').'-'.str_pad((string) $item->id, 10, '0', STR_PAD_LEFT))->values();
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

    private function getOrderedQuantitiesByIngredient(ProductionWave $wave): Collection
    {
        return SupplierOrderItem::query()
            ->whereHas('supplierOrder', function ($query) use ($wave): void {
                $query
                    ->where('production_wave_id', $wave->id)
                    ->whereIn('order_status', self::ORDER_PLACED_STATUSES);
            })
            ->with('supplierListing:id,ingredient_id')
            ->get()
            ->groupBy(fn (SupplierOrderItem $item): ?int => $item->supplierListing?->ingredient_id)
            ->map(fn (Collection $items): float => (float) $items->sum(fn (SupplierOrderItem $item): float => (float) ($item->quantity ?? 0) * (float) ($item->unit_weight ?? 0)))
            ->filter(fn (float $quantity, $ingredientId): bool => $ingredientId !== null && $quantity > 0)
            ->mapWithKeys(fn (float $quantity, $ingredientId): array => [(int) $ingredientId => $quantity]);
    }

    private function getReceivedQuantitiesByIngredient(ProductionWave $wave): Collection
    {
        return Supply::query()
            ->whereHas('supplierOrderItem.supplierOrder', function ($query) use ($wave): void {
                $query->where('production_wave_id', $wave->id);
            })
            ->with('supplierListing:id,ingredient_id')
            ->get()
            ->groupBy(fn (Supply $supply): ?int => $supply->supplierListing?->ingredient_id)
            ->map(fn (Collection $supplies): float => (float) $supplies->sum(fn (Supply $supply): float => (float) ($supply->quantity_in ?? $supply->initial_quantity ?? 0)))
            ->filter(fn (float $quantity, $ingredientId): bool => $ingredientId !== null && $quantity > 0)
            ->mapWithKeys(fn (float $quantity, $ingredientId): array => [(int) $ingredientId => $quantity]);
    }
}
