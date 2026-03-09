<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductType;
use Illuminate\Support\Facades\DB;

class ProductTypeProductionLineService
{
    /**
     * Normalize an allowed-lines selection and its default without touching the database.
     *
     * - Removes blanks, non-positive integers, and duplicates from $allowedProductionLineIds.
     * - Clears $defaultProductionLineId when it falls outside the normalized allowed set.
     * - Auto-selects the sole allowed line as default when only one remains and no default is set.
     *
     * @param  array<int, int|string|null>  $allowedProductionLineIds  Raw IDs from form state.
     * @param  int|null  $defaultProductionLineId  The currently selected default line ID.
     * @return array{allowed_production_line_ids: array<int, int>, default_production_line_id: int|null}
     */
    public function normalizeSelection(array $allowedProductionLineIds, ?int $defaultProductionLineId): array
    {
        $normalizedAllowedIds = collect($allowedProductionLineIds)
            ->filter(fn (mixed $lineId): bool => filled($lineId))
            ->map(fn (mixed $lineId): int => (int) $lineId)
            ->filter(fn (int $lineId): bool => $lineId > 0)
            ->unique()
            ->values()
            ->all();

        $normalizedDefaultLineId = $defaultProductionLineId !== null
            ? (int) $defaultProductionLineId
            : null;

        if ($normalizedDefaultLineId !== null && ! in_array($normalizedDefaultLineId, $normalizedAllowedIds, true)) {
            $normalizedDefaultLineId = null;
        }

        if ($normalizedDefaultLineId === null && count($normalizedAllowedIds) === 1) {
            $normalizedDefaultLineId = $normalizedAllowedIds[0];
        }

        return [
            'allowed_production_line_ids' => $normalizedAllowedIds,
            'default_production_line_id' => $normalizedDefaultLineId,
        ];
    }

    /**
     * Atomically sync allowed lines and default for a product type, then heal downstream productions.
     *
     * Within a single transaction:
     * 1. Normalizes the incoming selection.
     * 2. Syncs the pivot table.
     * 3. Updates default_production_line_id on the product type.
     * 4. Migrates Planned productions on removed lines to the new default (or null).
     * 5. Reports Confirmed productions that still reference removed lines (not auto-migrated).
     *
     * Ongoing, Finished, and Cancelled productions are intentionally left untouched.
     *
     * @param  array<int, int|string|null>  $allowedProductionLineIds  Raw IDs from form state.
     * @return array{
     *     allowed_production_line_ids: array<int, int>,
     *     default_production_line_id: int|null,
     *     migrated_planned_count: int,
     *     confirmed_conflict_count: int,
     *     confirmed_conflict_line_names: array<int, string>
     * }
     */
    public function sync(ProductType $productType, array $allowedProductionLineIds, ?int $defaultProductionLineId): array
    {
        return DB::transaction(function () use ($productType, $allowedProductionLineIds, $defaultProductionLineId): array {
            $productType->loadMissing('allowedProductionLines');

            $normalizedSelection = $this->normalizeSelection($allowedProductionLineIds, $defaultProductionLineId);
            $normalizedAllowedIds = $normalizedSelection['allowed_production_line_ids'];
            $normalizedDefaultLineId = $normalizedSelection['default_production_line_id'];

            $previousAllowedIds = $productType->allowedProductionLines->modelKeys();
            $removedAllowedIds = array_values(array_diff($previousAllowedIds, $normalizedAllowedIds));

            $productType->allowedProductionLines()->sync($normalizedAllowedIds);

            if ($productType->default_production_line_id !== $normalizedDefaultLineId) {
                $productType->forceFill([
                    'default_production_line_id' => $normalizedDefaultLineId,
                ])->saveQuietly();
            }

            $migratedPlannedCount = 0;
            $confirmedConflictCount = 0;
            $confirmedConflictLineNames = [];

            if ($removedAllowedIds !== []) {
                $migratedPlannedCount = $productType->productions()
                    ->whereIn('production_line_id', $removedAllowedIds)
                    ->where('status', ProductionStatus::Planned)
                    ->update([
                        'production_line_id' => $normalizedDefaultLineId,
                    ]);

                $confirmedConflictQuery = $productType->productions()
                    ->whereIn('production_line_id', $removedAllowedIds)
                    ->where('status', ProductionStatus::Confirmed);

                $confirmedConflictCount = $confirmedConflictQuery->count();

                if ($confirmedConflictCount > 0) {
                    $confirmedConflictLineIds = (clone $confirmedConflictQuery)
                        ->distinct()
                        ->pluck('production_line_id')
                        ->all();

                    $confirmedConflictLineNames = ProductionLine::query()
                        ->whereIn('id', $confirmedConflictLineIds)
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->pluck('name')
                        ->all();
                }
            }

            $productType->unsetRelation('allowedProductionLines');
            $productType->load('allowedProductionLines', 'defaultProductionLine');

            return [
                'allowed_production_line_ids' => $normalizedAllowedIds,
                'default_production_line_id' => $normalizedDefaultLineId,
                'migrated_planned_count' => $migratedPlannedCount,
                'confirmed_conflict_count' => $confirmedConflictCount,
                'confirmed_conflict_line_names' => $confirmedConflictLineNames,
            ];
        });
    }
}
