<?php

namespace App\Services\Production;

use App\Enums\OrderStatus;
use App\Enums\ProductionStatus;
use App\Enums\RequirementStatus;
use App\Models\Production\ProductionIngredientRequirement;
use App\Models\Production\ProductionWave;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use Illuminate\Support\Collection;

/**
 * Synchronizes wave requirement statuses from linked supplier orders and receipts.
 */
class WaveRequirementStatusService
{
    /**
     * @var array<int, OrderStatus>
     */
    private const ORDER_PLACED_STATUSES = [
        OrderStatus::Passed,
        OrderStatus::Confirmed,
        OrderStatus::Delivered,
        OrderStatus::Checked,
    ];

    /**
     * Reconciles requirement statuses by ingredient across ordered and received quantities.
     */
    public function syncForWave(ProductionWave $wave): void
    {
        $requirements = $this->getWaveRequirements($wave);

        if ($requirements->isEmpty()) {
            return;
        }

        $orderedByIngredient = $this->getOrderedQuantitiesByIngredient($wave);
        $receivedByIngredient = $this->getReceivedQuantitiesByIngredient($wave);

        $requirements
            ->groupBy('ingredient_id')
            ->each(function (Collection $ingredientRequirements, int|string $ingredientId) use ($orderedByIngredient, $receivedByIngredient): void {
                $remainingOrdered = (float) ($orderedByIngredient->get((int) $ingredientId) ?? 0);
                $remainingReceived = (float) ($receivedByIngredient->get((int) $ingredientId) ?? 0);

                foreach ($ingredientRequirements as $requirement) {
                    $requiredQuantity = (float) $requirement->required_quantity;

                    if ($requirement->isFulfilledByMasterbatch()) {
                        continue;
                    }

                    if ($requirement->status === RequirementStatus::Allocated) {
                        $remainingOrdered = max(0, $remainingOrdered - $requiredQuantity);
                        $remainingReceived = max(0, $remainingReceived - $requiredQuantity);

                        continue;
                    }

                    $nextStatus = RequirementStatus::NotOrdered;

                    if ($remainingReceived >= $requiredQuantity) {
                        $nextStatus = RequirementStatus::Received;
                    } elseif ($remainingOrdered >= $requiredQuantity) {
                        $nextStatus = RequirementStatus::Ordered;
                    }

                    if ($requirement->status !== $nextStatus) {
                        $requirement->update([
                            'status' => $nextStatus,
                        ]);
                    }

                    $remainingOrdered = max(0, $remainingOrdered - $requiredQuantity);
                    $remainingReceived = max(0, $remainingReceived - $requiredQuantity);
                }
            });
    }

    /**
     * Loads sortable wave requirements excluding masterbatch-fulfilled rows.
     *
     * @return Collection<int, ProductionIngredientRequirement>
     */
    private function getWaveRequirements(ProductionWave $wave): Collection
    {
        return ProductionIngredientRequirement::query()
            ->where('production_wave_id', $wave->id)
            ->whereHas('production', fn ($query) => $query->where('status', '!=', ProductionStatus::Cancelled->value))
            ->whereNull('fulfilled_by_masterbatch_id')
            ->with('production:id,production_date')
            ->get()
            ->sortBy(fn (ProductionIngredientRequirement $requirement): string => ($requirement->production?->production_date?->format('Y-m-d') ?? '9999-12-31').'-'.str_pad((string) $requirement->id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * Returns ordered quantities keyed by ingredient id for the wave.
     *
     * @return Collection<int, float>
     */
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

    /**
     * Returns received quantities keyed by ingredient id for the wave.
     *
     * @return Collection<int, float>
     */
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
