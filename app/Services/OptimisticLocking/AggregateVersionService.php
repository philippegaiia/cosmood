<?php

namespace App\Services\OptimisticLocking;

use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Models\Supply\SupplierOrder;

final class AggregateVersionService
{
    /**
     * Bump the lock version for a production aggregate.
     *
     * This should be called whenever meaningful state changes occur
     * that would invalidate an in-progress edit session.
     *
     * Triggers:
     * - Production model updates (via observer)
     * - ProductionItem changes (allocation status, supply assignment)
     * - ProductionTask changes (status, scheduled date)
     * - ProductionOutput changes
     * - ProductionQcCheck changes
     */
    public function bumpProductionVersion(Production $production): void
    {
        $production->increment('lock_version');

        if ($production->production_wave_id !== null) {
            $this->bumpProductionWaveVersionIfExists((int) $production->production_wave_id);
        }
    }

    /**
     * Bump version if the production still exists.
     *
     * Safe to call in contexts where the production might not exist.
     */
    public function bumpProductionVersionIfExists(int $productionId): void
    {
        $production = Production::find($productionId);

        if ($production) {
            $this->bumpProductionVersion($production);
        }
    }

    /**
     * Bump the lock version for a production wave aggregate.
     */
    public function bumpProductionWaveVersion(ProductionWave $wave): void
    {
        $wave->increment('lock_version');
    }

    public function bumpProductionWaveVersionIfExists(?int $waveId): void
    {
        if (! $waveId) {
            return;
        }

        $wave = ProductionWave::query()->find($waveId);

        if ($wave) {
            $this->bumpProductionWaveVersion($wave);
        }
    }

    /**
     * Bump the lock version for a supplier order aggregate.
     */
    public function bumpSupplierOrderVersion(SupplierOrder $order): void
    {
        $order->increment('lock_version');

        if ($order->production_wave_id !== null) {
            $this->bumpProductionWaveVersionIfExists((int) $order->production_wave_id);
        }
    }
}
