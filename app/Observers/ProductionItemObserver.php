<?php

namespace App\Observers;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Services\Production\ProductionConsumptionService;

/**
 * Keeps stock consumption synchronized when production items are edited after finish.
 */
class ProductionItemObserver
{
    public function __construct(
        private readonly ProductionConsumptionService $productionConsumptionService,
    ) {}

    public function created(ProductionItem $productionItem): void
    {
        $this->syncForProductionId($productionItem->production_id);
    }

    public function updated(ProductionItem $productionItem): void
    {
        $this->syncForProductionId($productionItem->production_id);

        if ($productionItem->wasChanged('production_id')) {
            $originalProductionId = (int) $productionItem->getRawOriginal('production_id');

            if ($originalProductionId > 0 && $originalProductionId !== (int) $productionItem->production_id) {
                $this->syncForProductionId($originalProductionId);
            }
        }
    }

    public function deleted(ProductionItem $productionItem): void
    {
        $this->syncForProductionId($productionItem->production_id);
    }

    private function syncForProductionId(?int $productionId): void
    {
        if (! $productionId) {
            return;
        }

        $production = Production::query()->find($productionId);

        if (! $production || $production->status !== ProductionStatus::Finished) {
            return;
        }

        $this->productionConsumptionService->consumeForFinishedProduction($production);
    }
}
