<?php

use App\Enums\QcInputType;
use App\Models\Production\Production;
use App\Models\Production\ProductType;
use App\Models\Production\QcTemplate;
use App\Models\Production\QcTemplateItem;
use App\Services\Production\ProductionQcGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function qcGenerationService(): ProductionQcGenerationService
{
    return app(ProductionQcGenerationService::class);
}

it('generates checks from product type default template', function () {
    $productType = ProductType::factory()->create();
    $template = QcTemplate::factory()->create([
        'product_type_id' => $productType->id,
        'is_default' => true,
        'is_active' => true,
    ]);

    QcTemplateItem::factory()->count(2)->create([
        'qc_template_id' => $template->id,
        'input_type' => QcInputType::Number,
    ]);

    $production = Production::factory()->create([
        'product_type_id' => $productType->id,
    ]);

    qcGenerationService()->generateChecksForProduction($production);

    expect($production->fresh()->productionQcChecks)->toHaveCount(2);
});

it('falls back to global template when no specific product type template exists', function () {
    $productType = ProductType::factory()->create();
    $globalTemplate = QcTemplate::factory()->globalDefault()->create();

    QcTemplateItem::factory()->create([
        'qc_template_id' => $globalTemplate->id,
        'label' => 'Aspect visuel conforme',
    ]);

    $production = Production::factory()->create([
        'product_type_id' => $productType->id,
    ]);

    qcGenerationService()->generateChecksForProduction($production);

    expect($production->fresh()->productionQcChecks)->toHaveCount(1)
        ->and($production->fresh()->productionQcChecks->first()->label)->toBe('Aspect visuel conforme');
});

it('does not duplicate checks when generation runs multiple times', function () {
    $productType = ProductType::factory()->create();
    $template = QcTemplate::factory()->create([
        'product_type_id' => $productType->id,
        'is_default' => true,
        'is_active' => true,
    ]);

    QcTemplateItem::factory()->count(3)->create([
        'qc_template_id' => $template->id,
    ]);

    $production = Production::factory()->create([
        'product_type_id' => $productType->id,
    ]);

    qcGenerationService()->generateChecksForProduction($production);
    qcGenerationService()->generateChecksForProduction($production);

    expect($production->fresh()->productionQcChecks)->toHaveCount(3);
});
