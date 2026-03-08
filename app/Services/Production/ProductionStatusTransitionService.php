<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use Illuminate\Support\Collection;

class ProductionStatusTransitionService
{
    /**
     * Confirm planned productions and return a transition summary.
     *
     * @param  Collection<int, Production>  $productions
     * @return array{confirmed: int, skipped: int, failed: int}
     */
    public function confirmPlannedProductions(Collection $productions): array
    {
        $confirmedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        foreach ($productions as $production) {
            if (! $production instanceof Production) {
                continue;
            }

            if ($production->status !== ProductionStatus::Planned) {
                $skippedCount++;

                continue;
            }

            try {
                $production->update(['status' => ProductionStatus::Confirmed]);
                $confirmedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        return [
            'confirmed' => $confirmedCount,
            'skipped' => $skippedCount,
            'failed' => $failedCount,
        ];
    }
}
