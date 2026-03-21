<?php

namespace App\Models\Production\Concerns;

use App\Models\Production\ProductionWave;
use App\Services\OptimisticLocking\AggregateVersionService;

trait BumpsParentProductionWaveVersion
{
    protected static function bootBumpsParentProductionWaveVersion(): void
    {
        static::saved(function (self $model): void {
            $model->bumpParentProductionWaveVersion();
        });

        static::deleted(function (self $model): void {
            $model->bumpParentProductionWaveVersion();
        });
    }

    protected function bumpParentProductionWaveVersion(): void
    {
        $wave = $this->getProductionWaveForVersionBump();

        if (! $wave) {
            return;
        }

        app(AggregateVersionService::class)->bumpProductionWaveVersion($wave);
    }

    protected function getProductionWaveForVersionBump(): ?ProductionWave
    {
        if (method_exists($this, 'wave')) {
            if ($this->relationLoaded('wave') && $this->wave) {
                return $this->wave;
            }

            if (isset($this->production_wave_id) && $this->production_wave_id) {
                return ProductionWave::query()->find($this->production_wave_id);
            }
        }

        return null;
    }
}
