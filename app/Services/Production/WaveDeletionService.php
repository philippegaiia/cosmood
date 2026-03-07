<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Enums\WaveStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItemAllocation;
use App\Models\Production\ProductionWave;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use Illuminate\Support\Facades\DB;

class WaveDeletionService
{
    /**
     * Deletes a wave and all associated productions permanently.
     *
     * Business invariants:
     * - No in-progress/completed wave deletion.
     * - No ongoing/finished productions in the wave.
     * - No reserved/consumed allocations remain.
     * - No committed PO quantities remain for the wave.
     */
    public function hardDeleteWaveWithProductions(ProductionWave $wave): void
    {
        $this->assertCanHardDeleteWave($wave);

        DB::transaction(function () use ($wave): void {
            $productions = Production::withTrashed()
                ->where('production_wave_id', $wave->id)
                ->with(['productionItems.allocations'])
                ->get();

            foreach ($productions as $production) {
                $production->forceDelete();
            }

            $wave->forceDelete();
        });
    }

    /**
     * @return array<int, string>
     */
    public function getHardDeleteBlockers(ProductionWave $wave): array
    {
        $blockers = [];

        if (in_array($wave->status, [WaveStatus::InProgress, WaveStatus::Completed], true)) {
            $blockers[] = __('La vague est en cours ou terminée.');
        }

        $productionIds = Production::withTrashed()
            ->where('production_wave_id', $wave->id)
            ->pluck('id');

        $committedOrderItems = SupplierOrderItem::query()
            ->where('committed_quantity_kg', '>', 0)
            ->whereHas('supplierOrder', fn ($query) => $query->where('production_wave_id', $wave->id))
            ->with('supplierOrder:id,order_ref,serial_number,production_wave_id')
            ->limit(5)
            ->get();

        $committedOpenOrderCount = SupplierOrderItem::query()
            ->where('committed_quantity_kg', '>', 0)
            ->whereHas('supplierOrder', fn ($query) => $query->where('production_wave_id', $wave->id))
            ->count();

        if ($committedOpenOrderCount > 0) {
            $orderReferences = $committedOrderItems
                ->map(fn (SupplierOrderItem $item): string => (string) ($item->supplierOrder?->order_ref ?: '#'.$item->supplier_order_id))
                ->unique()
                ->implode(', ');

            $blockers[] = __('Engagements PO actifs (:count). Commandes: :orders. Retirez les engagements ou la référence vague sur ces commandes.', [
                'count' => (string) $committedOpenOrderCount,
                'orders' => $orderReferences !== '' ? $orderReferences : __('N/A'),
            ]);
        }

        if ($productionIds->isEmpty()) {
            return $blockers;
        }

        $blockingProductions = Production::withTrashed()
            ->whereIn('id', $productionIds)
            ->whereIn('status', [ProductionStatus::Ongoing, ProductionStatus::Finished])
            ->pluck('batch_number')
            ->take(5)
            ->values();

        if ($blockingProductions->isNotEmpty()) {
            $blockers[] = __('Productions en cours/terminées présentes: :batches', [
                'batches' => $blockingProductions->implode(', '),
            ]);
        }

        $reservedAllocationsCount = ProductionItemAllocation::query()
            ->where('status', 'reserved')
            ->whereHas('productionItem', fn ($query) => $query->whereIn('production_id', $productionIds))
            ->count();

        if ($reservedAllocationsCount > 0) {
            $blockers[] = __('Allocations réservées actives (:count). Désallouez manuellement avant suppression.', [
                'count' => (string) $reservedAllocationsCount,
            ]);
        }

        $consumedAllocationsCount = ProductionItemAllocation::query()
            ->where('status', 'consumed')
            ->whereHas('productionItem', fn ($query) => $query->whereIn('production_id', $productionIds))
            ->count();

        if ($consumedAllocationsCount > 0) {
            $blockers[] = __('Allocations consommées détectées (:count). Suppression interdite pour préserver la traçabilité.', [
                'count' => (string) $consumedAllocationsCount,
            ]);
        }

        $producedSupplyCount = Supply::query()
            ->whereIn('source_production_id', $productionIds)
            ->count();

        if ($producedSupplyCount > 0) {
            $blockers[] = __('Stocks fabriqués liés à cette vague (:count). Suppression interdite.', [
                'count' => (string) $producedSupplyCount,
            ]);
        }

        return $blockers;
    }

    public function assertCanHardDeleteWave(ProductionWave $wave): void
    {
        $blockers = $this->getHardDeleteBlockers($wave);

        if ($blockers === []) {
            return;
        }

        throw new \InvalidArgumentException(__('Suppression définitive impossible: :reasons', [
            'reasons' => implode(' | ', $blockers),
        ]));
    }
}
