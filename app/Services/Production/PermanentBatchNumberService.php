<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionBatchSequence;
use Illuminate\Support\Facades\DB;

/**
 * Assigns globally sequential permanent batch numbers with row-level locking.
 */
class PermanentBatchNumberService
{
    private const SEQUENCE_NAME = 'production_permanent_batch';

    private const SERIAL_PADDING = 6;

    /**
     * Assigns a permanent number to a production when eligible and missing.
     */
    public function assignIfMissing(Production $production): ?string
    {
        if (! $this->canHavePermanentBatchNumber($production)) {
            return null;
        }

        return DB::transaction(function () use ($production): ?string {
            /** @var Production $lockedProduction */
            $lockedProduction = Production::query()
                ->lockForUpdate()
                ->findOrFail($production->id);

            if (filled($lockedProduction->permanent_batch_number)) {
                return $lockedProduction->permanent_batch_number;
            }

            $nextSerial = $this->nextSerialNumber();

            $lockedProduction->update([
                'permanent_batch_number' => str_pad((string) $nextSerial, self::SERIAL_PADDING, '0', STR_PAD_LEFT),
            ]);

            return $lockedProduction->permanent_batch_number;
        }, attempts: 5);
    }

    /**
     * Assigns permanent numbers in chronological order for selected productions.
     *
     * @param  array<int, int>  $productionIds
     */
    public function assignForProductions(array $productionIds): int
    {
        if ($productionIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($productionIds): int {
            $sequence = ProductionBatchSequence::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['name' => self::SEQUENCE_NAME],
                    ['current_value' => 0],
                );

            $currentValue = (int) $sequence->current_value;
            $assignedCount = 0;

            $productions = Production::query()
                ->whereIn('id', $productionIds)
                ->orderBy('production_date')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($productions as $production) {
                if (! $this->canHavePermanentBatchNumber($production) || filled($production->permanent_batch_number)) {
                    continue;
                }

                $currentValue++;

                $production->update([
                    'permanent_batch_number' => str_pad((string) $currentValue, self::SERIAL_PADDING, '0', STR_PAD_LEFT),
                ]);

                $assignedCount++;
            }

            if ($assignedCount > 0) {
                $sequence->update([
                    'current_value' => $currentValue,
                ]);
            }

            return $assignedCount;
        }, attempts: 5);
    }

    /**
     * Returns and persists the next serial from the dedicated sequence row.
     */
    private function nextSerialNumber(): int
    {
        $sequence = ProductionBatchSequence::query()
            ->lockForUpdate()
            ->firstOrCreate(
                ['name' => self::SEQUENCE_NAME],
                ['current_value' => 0],
            );

        $nextValue = (int) $sequence->current_value + 1;

        $sequence->update([
            'current_value' => $nextValue,
        ]);

        return $nextValue;
    }

    /**
     * Limits permanent numbering to lifecycle states that can become real lots.
     */
    private function canHavePermanentBatchNumber(Production $production): bool
    {
        return in_array($production->status, [
            ProductionStatus::Planned,
            ProductionStatus::Confirmed,
            ProductionStatus::Ongoing,
            ProductionStatus::Finished,
        ], true);
    }
}
