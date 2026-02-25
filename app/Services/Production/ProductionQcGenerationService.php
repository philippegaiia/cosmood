<?php

namespace App\Services\Production;

use App\Enums\QcResult;
use App\Models\Production\Production;
use App\Models\Production\ProductionQcCheck;
use App\Models\Production\ProductType;
use App\Models\Production\QcTemplate;

/**
 * Resolves QC templates and instantiates QC checks for productions.
 */
class ProductionQcGenerationService
{
    /**
     * Generates QC checks from the best matching template.
     */
    public function generateChecksForProduction(Production $production): bool
    {
        $template = $this->getTemplateForProduction($production);

        if (! $template) {
            return false;
        }

        $this->generateFromTemplate($production, $template);

        return true;
    }

    /**
     * Resolves product-type assigned template first, then fallback global default template.
     */
    public function getTemplateForProduction(Production $production): ?QcTemplate
    {
        $productTypeId = $production->product_type_id ?? $production->product?->product_type_id;

        if ($productTypeId) {
            $assignedTemplateId = ProductType::query()
                ->whereKey((int) $productTypeId)
                ->value('qc_template_id');

            if ($assignedTemplateId) {
                $assignedTemplate = QcTemplate::query()
                    ->whereKey((int) $assignedTemplateId)
                    ->where('is_active', true)
                    ->first();

                if ($assignedTemplate) {
                    return $assignedTemplate;
                }
            }
        }

        return QcTemplate::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereDoesntHave('productTypes')
            ->first();
    }

    /**
     * Creates missing QC check rows from template items.
     */
    public function generateFromTemplate(Production $production, QcTemplate $template): void
    {
        $existingTemplateItemIds = $production->productionQcChecks()
            ->whereNotNull('qc_template_item_id')
            ->pluck('qc_template_item_id')
            ->toArray();

        $template->loadMissing('items');

        $template->items
            ->reject(fn ($item): bool => in_array($item->id, $existingTemplateItemIds, true))
            ->each(function ($item) use ($production): void {
                ProductionQcCheck::query()->create([
                    'production_id' => $production->id,
                    'qc_template_item_id' => $item->id,
                    'label' => $item->label,
                    'input_type' => $item->input_type,
                    'unit' => $item->unit,
                    'min_value' => $item->min_value,
                    'max_value' => $item->max_value,
                    'target_value' => $item->target_value,
                    'options' => $item->options,
                    'stage' => $item->stage,
                    'required' => $item->required,
                    'sort_order' => $item->sort_order,
                    'result' => QcResult::Pending,
                ]);
            });
    }
}
