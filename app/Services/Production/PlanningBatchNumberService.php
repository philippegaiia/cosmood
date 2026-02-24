<?php

namespace App\Services\Production;

use App\Models\Production\Production;
use App\Models\Production\ProductionBatchSequence;
use Illuminate\Support\Facades\DB;

/**
 * Generates short planning batch references like T00001 for production creation.
 */
class PlanningBatchNumberService
{
    private const SEQUENCE_NAME = 'production_planning_batch';

    private const PREFIX = 'T';

    private const SERIAL_PADDING = 5;

    public function generateNextReference(): string
    {
        return DB::transaction(function (): string {
            $next = $this->nextSerialNumber();

            $candidate = $this->formatReference($next);

            while (Production::query()->where('batch_number', $candidate)->exists()) {
                $next = $this->nextSerialNumber();
                $candidate = $this->formatReference($next);
            }

            return $candidate;
        }, attempts: 5);
    }

    private function nextSerialNumber(): int
    {
        $sequence = ProductionBatchSequence::query()
            ->lockForUpdate()
            ->firstOrCreate(
                ['name' => self::SEQUENCE_NAME],
                ['current_value' => $this->resolveInitialValue()],
            );

        $nextValue = (int) $sequence->current_value + 1;

        $sequence->update([
            'current_value' => $nextValue,
        ]);

        return $nextValue;
    }

    private function resolveInitialValue(): int
    {
        return (int) Production::query()
            ->pluck('batch_number')
            ->map(function (?string $batchNumber): int {
                if (! $batchNumber) {
                    return 0;
                }

                if (! preg_match('/^T(\d{5})$/', $batchNumber, $matches)) {
                    return 0;
                }

                return (int) $matches[1];
            })
            ->max();
    }

    private function formatReference(int $serial): string
    {
        return self::PREFIX.str_pad((string) $serial, self::SERIAL_PADDING, '0', STR_PAD_LEFT);
    }
}
