<?php

namespace App\Services\Production;

use App\Enums\RequirementStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionPackagingRequirement;
use Illuminate\Support\Facades\DB;

/**
 * Generates and maintains packaging requirements derived from expected units.
 */
class PackagingRequirementsService
{
    /**
     * Creates missing packaging requirements for a production.
     *
     * @param  array<int, array{code: string, name: string, quantity_per_unit?: int|float, supplier_id?: int|null, unit_cost?: float|null, notes?: string|null}>  $packagingData
     */
    public function generateRequirements(Production $production, array $packagingData): void
    {
        $expectedUnits = $production->expected_units ?? 0;

        if ($expectedUnits <= 0) {
            return;
        }

        DB::transaction(function () use ($production, $packagingData, $expectedUnits) {
            foreach ($packagingData as $item) {
                $existingRequirement = $production->packagingRequirements()
                    ->where('packaging_code', $item['code'])
                    ->first();

                if ($existingRequirement) {
                    continue;
                }

                $quantityPerUnit = $item['quantity_per_unit'] ?? 1;
                $requiredQuantity = (int) ceil($expectedUnits * $quantityPerUnit);

                ProductionPackagingRequirement::create([
                    'production_id' => $production->id,
                    'production_wave_id' => $production->production_wave_id,
                    'packaging_name' => $item['name'],
                    'packaging_code' => $item['code'],
                    'required_quantity' => $requiredQuantity,
                    'quantity_per_unit' => $quantityPerUnit,
                    'supplier_id' => $item['supplier_id'] ?? null,
                    'unit_cost' => $item['unit_cost'] ?? null,
                    'status' => RequirementStatus::NotOrdered,
                    'allocated_quantity' => 0,
                    'notes' => $item['notes'] ?? null,
                ]);
            }
        });
    }

    /**
     * Fully rebuilds packaging requirements from current packaging inputs.
     *
     * @param  array<int, array{code: string, name: string, quantity_per_unit?: int|float, supplier_id?: int|null, unit_cost?: float|null, notes?: string|null}>  $packagingData
     */
    public function regenerateRequirements(Production $production, array $packagingData): void
    {
        DB::transaction(function () use ($production, $packagingData) {
            $production->packagingRequirements()->delete();
            $production->load('packagingRequirements');
            $production->refresh();
            $this->generateRequirements($production, $packagingData);
        });
    }

    /**
     * Recomputes required packaging quantities when expected units change.
     */
    public function updateQuantities(Production $production): void
    {
        $expectedUnits = $production->expected_units ?? 0;

        if ($expectedUnits <= 0) {
            return;
        }

        DB::transaction(function () use ($production, $expectedUnits) {
            foreach ($production->packagingRequirements as $requirement) {
                $newQuantity = (int) ceil($expectedUnits * $requirement->quantity_per_unit);
                $requirement->update(['required_quantity' => $newQuantity]);
            }
        });
    }

    /**
     * Returns status counters for packaging requirement progress.
     *
     * @return array{not_ordered: int, ordered: int, received: int, allocated: int, total: int}
     */
    public function getSummary(Production $production): array
    {
        $requirements = $production->packagingRequirements;

        return [
            'not_ordered' => $requirements->where('status', RequirementStatus::NotOrdered)->count(),
            'ordered' => $requirements->where('status', RequirementStatus::Ordered)->count(),
            'received' => $requirements->where('status', RequirementStatus::Received)->count(),
            'allocated' => $requirements->where('status', RequirementStatus::Allocated)->count(),
            'total' => $requirements->count(),
        ];
    }
}
