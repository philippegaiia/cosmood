<?php

namespace App\Services\Production;

use App\Enums\OrderStatus;
use App\Enums\RequirementStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionIngredientRequirement;
use App\Models\Production\ProductionWave;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WaveProcurementService
{
    public function __construct(private readonly ProductionRequirementsService $productionRequirementsService) {}

    public function aggregateRequirements(ProductionWave $wave): Collection
    {
        $this->ensureWaveRequirements($wave);

        $requirements = ProductionIngredientRequirement::query()
            ->where('production_wave_id', $wave->id)
            ->whereNull('fulfilled_by_masterbatch_id')
            ->whereIn('status', [RequirementStatus::NotOrdered, RequirementStatus::Ordered])
            ->with(['ingredient', 'supplierListing.supplier'])
            ->get();

        return $requirements
            ->filter(fn ($r) => $r->getRemainingQuantity() > 0)
            ->groupBy(fn ($r) => $r->ingredient_id.'-'.$r->supplier_listing_id)
            ->map(function ($group) {
                $first = $group->first();

                return (object) [
                    'ingredient_id' => $first->ingredient_id,
                    'supplier_listing_id' => $first->supplier_listing_id,
                    'supplier_listing' => $first->supplierListing,
                    'total_quantity' => $group->sum(fn ($r) => $r->getRemainingQuantity()),
                    'requirements' => $group,
                ];
            })
            ->values();
    }

    public function getPlanningList(ProductionWave $wave): Collection
    {
        $this->ensureWaveRequirements($wave);

        $requirements = ProductionIngredientRequirement::query()
            ->where('production_wave_id', $wave->id)
            ->whereNull('fulfilled_by_masterbatch_id')
            ->whereIn('status', [RequirementStatus::NotOrdered, RequirementStatus::Ordered])
            ->with('ingredient')
            ->get()
            ->filter(fn (ProductionIngredientRequirement $requirement): bool => $requirement->getRemainingQuantity() > 0);

        $stockByIngredient = Supply::query()
            ->where('is_in_stock', true)
            ->with('supplierListing:id,ingredient_id')
            ->get()
            ->groupBy(fn (Supply $supply): ?int => $supply->supplierListing?->ingredient_id)
            ->map(fn (Collection $supplies): float => (float) $supplies->sum(fn (Supply $supply): float => $supply->getAvailableQuantity()));

        return $requirements
            ->groupBy('ingredient_id')
            ->map(function (Collection $group, int|string $ingredientId) use ($stockByIngredient): object {
                $notOrderedRequirements = $group->where('status', RequirementStatus::NotOrdered);
                $orderedRequirements = $group->where('status', RequirementStatus::Ordered);

                $ingredient = $group->first()?->ingredient;
                $notOrderedQuantity = (float) $notOrderedRequirements->sum(fn ($requirement): float => $requirement->getRemainingQuantity());
                $orderedQuantity = (float) $orderedRequirements->sum(fn ($requirement): float => $requirement->getRemainingQuantity());
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
                    'requirements' => $group,
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

        $this->ensureWaveRequirements($wave);

        $aggregated = $this->aggregateRequirements($wave)
            ->map(function (object $item): object {
                $notOrderedRequirements = $item->requirements->where('status', RequirementStatus::NotOrdered);

                $item->to_order_quantity = (float) $notOrderedRequirements
                    ->sum(fn ($requirement): float => $requirement->getRemainingQuantity());

                $item->not_ordered_requirement_ids = $notOrderedRequirements->pluck('id')->values();

                return $item;
            })
            ->filter(fn (object $item): bool => $item->to_order_quantity > 0)
            ->values();

        if ($aggregated->isEmpty()) {
            return collect();
        }

        $orders = collect();

        DB::transaction(function () use ($aggregated, &$orders) {
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

                    ProductionIngredientRequirement::query()
                        ->whereIn('id', $item->not_ordered_requirement_ids)
                        ->where('status', RequirementStatus::NotOrdered)
                        ->update(['status' => RequirementStatus::Ordered]);
                }

                $orders->push($order->load('supplier_order_items'));
            }
        });

        return $orders;
    }

    public function getProcurementSummary(ProductionWave $wave): array
    {
        $this->ensureWaveRequirements($wave);

        $requirements = ProductionIngredientRequirement::query()
            ->where('production_wave_id', $wave->id)
            ->get();

        return [
            'not_ordered' => $requirements->where('status', RequirementStatus::NotOrdered)->count(),
            'ordered' => $requirements->where('status', RequirementStatus::Ordered)->count(),
            'received' => $requirements->where('status', RequirementStatus::Received)->count(),
            'allocated' => $requirements->where('status', RequirementStatus::Allocated)->count(),
            'total' => $requirements->count(),
        ];
    }

    protected function getNextSerialNumber(): int
    {
        $lastOrder = SupplierOrder::orderBy('id', 'desc')->first();

        return $lastOrder ? $lastOrder->serial_number + 1 : 1001;
    }

    private function ensureWaveRequirements(ProductionWave $wave): void
    {
        $wave->loadMissing('productions');

        $wave->productions->each(function (Production $production): void {
            $this->productionRequirementsService->generateRequirements($production);
        });
    }
}
