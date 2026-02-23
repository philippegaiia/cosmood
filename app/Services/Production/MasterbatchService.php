<?php

namespace App\Services\Production;

use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use Illuminate\Support\Collection;

class MasterbatchService
{
    public function selectMasterbatch(Production $production, Production $masterbatch): void
    {
        $this->validateMasterbatch($production, $masterbatch);

        $production->update(['masterbatch_lot_id' => $masterbatch->id]);

        $this->collapsePhaseRequirements($production, $masterbatch);
    }

    public function removeMasterbatch(Production $production): void
    {
        $production->update(['masterbatch_lot_id' => null]);

        $production->ingredientRequirements()
            ->whereNotNull('fulfilled_by_masterbatch_id')
            ->update([
                'fulfilled_by_masterbatch_id' => null,
                'is_collapsed_in_ui' => false,
            ]);
    }

    protected function validateMasterbatch(Production $production, Production $masterbatch): void
    {
        if (! $masterbatch->is_masterbatch) {
            throw new \InvalidArgumentException('Selected production is not a masterbatch');
        }

        if ($masterbatch->status !== ProductionStatus::Finished) {
            throw new \InvalidArgumentException('Masterbatch must be finished');
        }

        if (! $this->isMasterbatchCompatible($production, $masterbatch)) {
            throw new \InvalidArgumentException('Masterbatch phase mismatch');
        }
    }

    protected function collapsePhaseRequirements(Production $production, Production $masterbatch): void
    {
        $replacedPhase = $this->normalizePhase($masterbatch->replaces_phase);

        if (! $replacedPhase) {
            return;
        }

        $production->ingredientRequirements()
            ->where('phase', $replacedPhase)
            ->update([
                'fulfilled_by_masterbatch_id' => $masterbatch->id,
                'is_collapsed_in_ui' => true,
            ]);
    }

    public function isMasterbatchCompatible(Production $production, Production $masterbatch): bool
    {
        $replacedPhase = $this->normalizePhase($masterbatch->replaces_phase);

        if (! $replacedPhase) {
            return false;
        }

        $hasPhase = $production->ingredientRequirements()
            ->where('phase', $replacedPhase)
            ->exists();

        return $hasPhase;
    }

    public function getExpandedIngredients(Production $production): Collection
    {
        $masterbatch = $production->masterbatchLot;

        if (! $masterbatch) {
            return collect();
        }

        $mbFormula = $masterbatch->formula;

        if (! $mbFormula) {
            return collect();
        }

        $expandedIngredients = collect();

        foreach ($mbFormula->formulaItems as $formulaItem) {
            $expandedIngredients->push([
                'ingredient_id' => $formulaItem->ingredient_id,
                'ingredient_name' => $formulaItem->ingredient->name ?? null,
                'percentage' => $formulaItem->percentage_of_oils,
                'phase' => $this->normalizePhase($formulaItem->phase) ?? $formulaItem->phase,
                'masterbatch_id' => $masterbatch->id,
                'masterbatch_batch_number' => $masterbatch->batch_number,
            ]);
        }

        return $expandedIngredients;
    }

    public function getCollapsedRequirements(Production $production): Collection
    {
        return $production->ingredientRequirements()
            ->where('is_collapsed_in_ui', true)
            ->whereNotNull('fulfilled_by_masterbatch_id')
            ->get();
    }

    public function getVisibleRequirements(Production $production): Collection
    {
        return $production->ingredientRequirements()
            ->where('is_collapsed_in_ui', false)
            ->get();
    }

    public function getMasterbatchLine(Production $production): ?array
    {
        $masterbatch = $production->masterbatchLot;

        if (! $masterbatch) {
            return null;
        }

        $replacedPhase = $this->normalizePhase($masterbatch->replaces_phase);

        if (! $replacedPhase) {
            return null;
        }

        $collapsedReqs = $this->getCollapsedRequirements($production)
            ->where('phase', $replacedPhase);

        $totalQuantity = $collapsedReqs->isNotEmpty()
            ? (float) $collapsedReqs->sum('required_quantity')
            : (float) $production->productionItems()
                ->where('phase', $replacedPhase)
                ->get()
                ->sum(fn ($item): float => $item->getCalculatedQuantityKg());

        if ($totalQuantity <= 0) {
            return null;
        }

        return [
            'masterbatch_id' => $masterbatch->id,
            'masterbatch_batch_number' => $masterbatch->batch_number,
            'phase' => $replacedPhase,
            'quantity' => $totalQuantity,
            'ingredients' => $this->getExpandedIngredients($production),
        ];
    }

    public function getMasterbatchTraceabilityLines(Production $production): Collection
    {
        $masterbatch = $production->masterbatchLot;

        if (! $masterbatch) {
            return collect();
        }

        $replacedPhase = $this->normalizePhase($masterbatch->replaces_phase);

        if (! $replacedPhase) {
            return collect();
        }

        $masterbatch->loadMissing('productionItems.ingredient', 'productionItems.supply');

        return $masterbatch->productionItems
            ->where('phase', $replacedPhase)
            ->sortBy('sort')
            ->values()
            ->map(function ($item): array {
                return [
                    'ingredient_name' => $item->ingredient?->name,
                    'phase' => $item->phase,
                    'quantity' => $item->getCalculatedQuantityKg(),
                    'supply_batch_number' => $item->supply_batch_number,
                    'supply_ref' => $item->supply?->order_ref,
                ];
            });
    }

    public function applyTraceabilityToProductionItems(Production $production): int
    {
        $masterbatch = $production->masterbatchLot;

        if (! $masterbatch) {
            return 0;
        }

        $replacedPhase = $this->normalizePhase($masterbatch->replaces_phase);

        if (! $replacedPhase) {
            return 0;
        }

        $masterbatch->loadMissing('productionItems');
        $production->loadMissing('productionItems');

        $sourceByIngredient = $masterbatch->productionItems
            ->where('phase', $replacedPhase)
            ->keyBy('ingredient_id');

        $updated = 0;

        foreach ($production->productionItems->where('phase', $replacedPhase) as $item) {
            $source = $sourceByIngredient->get($item->ingredient_id);

            if (! $source) {
                continue;
            }

            $changes = [];

            if ($source->supplier_listing_id !== null) {
                $changes['supplier_listing_id'] = $source->supplier_listing_id;
            }

            if ($source->supply_id !== null) {
                $changes['supply_id'] = $source->supply_id;
            }

            if (filled($source->supply_batch_number)) {
                $changes['supply_batch_number'] = $source->supply_batch_number;
            }

            if ($changes === []) {
                continue;
            }

            $changes['is_supplied'] = true;

            $hasChanges = ! $item->is_supplied;

            foreach ($changes as $column => $value) {
                if ((string) ($item->{$column} ?? '') !== (string) ($value ?? '')) {
                    $hasChanges = true;

                    break;
                }
            }

            if ($hasChanges) {
                $item->update($changes);
                $updated++;
            }
        }

        return $updated;
    }

    public function getPercentageMismatches(Production $production): Collection
    {
        $masterbatch = $production->masterbatchLot;

        if (! $masterbatch) {
            return collect();
        }

        $replacedPhase = $this->normalizePhase($masterbatch->replaces_phase);

        if (! $replacedPhase) {
            return collect();
        }

        $masterbatch->loadMissing('productionItems.ingredient');
        $production->loadMissing('productionItems.ingredient');

        $sourceByIngredient = $masterbatch->productionItems
            ->where('phase', $replacedPhase)
            ->keyBy('ingredient_id');

        return $production->productionItems
            ->where('phase', $replacedPhase)
            ->map(function ($item) use ($sourceByIngredient): ?array {
                $source = $sourceByIngredient->get($item->ingredient_id);

                if (! $source) {
                    return null;
                }

                $targetPercentage = (float) ($item->percentage_of_oils ?? 0);
                $sourcePercentage = (float) ($source->percentage_of_oils ?? 0);

                if (abs($targetPercentage - $sourcePercentage) < 0.001) {
                    return null;
                }

                return [
                    'ingredient_id' => $item->ingredient_id,
                    'ingredient_name' => $item->ingredient?->name,
                    'production_percentage' => round($targetPercentage, 3),
                    'masterbatch_percentage' => round($sourcePercentage, 3),
                ];
            })
            ->filter()
            ->values();
    }

    private function normalizePhase(Phases|string|null $phase): ?string
    {
        if ($phase === null) {
            return null;
        }

        if ($phase instanceof Phases) {
            return $phase->value;
        }

        return match ($phase) {
            'saponified_oils' => Phases::Saponification->value,
            'lye' => Phases::Lye->value,
            'additives' => Phases::Additives->value,
            default => $phase,
        };
    }
}
