<?php

namespace App\Models\Production\Concerns;

use App\Models\Production\Production;
use App\Services\OptimisticLocking\AggregateVersionService;

/**
 * Trait for child models that should bump the parent Production's lock_version.
 *
 * When a child model (ProductionItem, ProductionTask, etc.) is created, updated,
 * or deleted, the parent Production's lock_version should be incremented so that
 * open edit sessions can detect conflicts.
 *
 * Usage:
 *   class ProductionItem extends Model {
 *       use BumpsParentProductionVersion;
 *   }
 */
trait BumpsParentProductionVersion
{
    protected static function bootBumpsParentProductionVersion(): void
    {
        static::saved(function (self $model): void {
            $model->bumpParentProductionVersion();
        });

        static::deleted(function (self $model): void {
            $model->bumpParentProductionVersion();
        });
    }

    protected function bumpParentProductionVersion(): void
    {
        $production = $this->getProductionForVersionBump();

        if (! $production) {
            return;
        }

        app(AggregateVersionService::class)->bumpProductionVersion($production);
    }

    /**
     * Get the production instance for version bumping.
     *
     * Override this method in child models to customize how the production is retrieved.
     */
    protected function getProductionForVersionBump(): ?Production
    {
        if (method_exists($this, 'production')) {
            if ($this->relationLoaded('production') && $this->production) {
                return $this->production;
            }

            if (isset($this->production_id) && $this->production_id) {
                return Production::query()->find($this->production_id);
            }
        }

        return null;
    }
}
