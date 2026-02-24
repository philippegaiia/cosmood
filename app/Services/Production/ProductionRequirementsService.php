<?php

namespace App\Services\Production;

use App\Enums\Phases;
use App\Enums\RequirementStatus;
use App\Models\Production\Formula;
use App\Models\Production\Production;
use App\Models\Production\ProductionIngredientRequirement;
use Illuminate\Support\Facades\DB;

/**
 * Generates and maintains ingredient requirements from production formulas.
 */
class ProductionRequirementsService
{
    /**
     * Creates requirements when none exist for the production.
     */
    public function generateRequirements(Production $production): void
    {
        if ($production->ingredientRequirements()->exists()) {
            return;
        }

        $formula = $production->formula;

        if (! $formula) {
            return;
        }

        $batchSize = $production->planned_quantity ?? 0;

        DB::transaction(function () use ($production, $formula, $batchSize) {
            foreach ($formula->formulaItems as $formulaItem) {
                $this->createRequirement($production, $formulaItem, $batchSize);
            }
        });
    }

    /**
     * Rebuilds all ingredient requirements for a production.
     */
    public function regenerateRequirements(Production $production): void
    {
        DB::transaction(function () use ($production) {
            $production->ingredientRequirements()->delete();
            $production->load('ingredientRequirements');

            $production->refresh();

            $this->generateRequirements($production);
        });
    }

    /**
     * Creates one ingredient requirement from one formula item.
     */
    protected function createRequirement(Production $production, $formulaItem, float $batchSize): ProductionIngredientRequirement
    {
        $requiredQuantity = $this->calculateQuantity(
            $formulaItem->percentage_of_oils,
            $batchSize,
            $formulaItem->phase
        );

        return ProductionIngredientRequirement::create([
            'production_id' => $production->id,
            'production_wave_id' => $production->production_wave_id,
            'ingredient_id' => $formulaItem->ingredient_id,
            'phase' => $formulaItem->phase,
            'supplier_listing_id' => null,
            'required_quantity' => $requiredQuantity,
            'status' => RequirementStatus::NotOrdered,
            'allocated_quantity' => 0,
            'allocated_from_supply_id' => null,
            'fulfilled_by_masterbatch_id' => null,
            'is_collapsed_in_ui' => false,
            'notes' => null,
        ]);
    }

    /**
     * Converts formula percentages into required quantities for the production batch.
     */
    public function calculateQuantity(float $percentage, float $batchSize, Phases|string $phase): float
    {
        $phaseValue = $phase instanceof Phases ? $phase->value : $phase;

        if ($phaseValue === Phases::Saponification->value) {
            return round(($percentage / 100) * $batchSize, 3);
        }

        $oilsPhasePercentage = $this->getOilsPhasePercentage($batchSize);

        return round(($percentage / 100) * $oilsPhasePercentage, 3);
    }

    /**
     * Returns the base quantity used for non-saponification phase calculations.
     */
    protected function getOilsPhasePercentage(float $batchSize): float
    {
        return $batchSize;
    }

    /**
     * Returns the total formula percentage allocated to the saponification phase.
     */
    public function getTotalOilsPercentage(Formula $formula): float
    {
        return $formula->formulaItems()
            ->where('phase', Phases::Saponification->value)
            ->sum('percentage_of_oils');
    }

    /**
     * Groups requirements by phase with per-phase quantity totals.
     *
     * @return array<string, array{items: \Illuminate\Support\Collection, total_quantity: float|int}>
     */
    public function getRequirementsByPhase(Production $production): array
    {
        return $production->ingredientRequirements
            ->groupBy('phase')
            ->map(fn ($requirements) => [
                'items' => $requirements,
                'total_quantity' => $requirements->sum('required_quantity'),
            ])
            ->toArray();
    }

    /**
     * Updates one requirement status explicitly.
     */
    public function updateRequirementStatus(ProductionIngredientRequirement $requirement, RequirementStatus $status): void
    {
        $requirement->update(['status' => $status]);
    }
}
