<?php

use App\Filament\Pages\PlanningBoard;
use App\Filament\Pages\SimulateurFlash;
use App\Filament\Pages\WaveProcurementOverview;
use App\Filament\Resources\Production\FormulaResource;
use App\Filament\Resources\Production\ProductionResource;
use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use App\Filament\Resources\Production\ProductResource\ProductResource;
use App\Filament\Resources\Production\ProductTypes\ProductTypeResource;
use App\Filament\Resources\Supply\IngredientResource;
use App\Filament\Resources\Supply\SupplierListingResource;
use App\Filament\Resources\Supply\SupplierOrderResource;
use App\Filament\Resources\Supply\SupplyResource;
use Filament\Facades\Filament;
use Guava\FilamentKnowledgeBase\Plugins\KnowledgeBaseCompanionPlugin;
use Guava\FilamentKnowledgeBase\Plugins\KnowledgeBasePlugin;
use Illuminate\Support\Facades\File;

it('registers the knowledge base plugins on the expected panels', function (): void {
    expect(Filament::getPanel('admin')->hasPlugin(KnowledgeBaseCompanionPlugin::ID))->toBeTrue()
        ->and(Filament::getPanel('knowledge-base')->hasPlugin(KnowledgeBasePlugin::ID))->toBeTrue();

    /** @var KnowledgeBasePlugin $knowledgeBasePlugin */
    $knowledgeBasePlugin = Filament::getPanel('knowledge-base')->getPlugin(KnowledgeBasePlugin::ID);

    expect($knowledgeBasePlugin->shouldDisableBackToDefaultPanelButton())->toBeFalse()
        ->and($knowledgeBasePlugin->getAnchorSymbol())->toBeNull();
});

it('opens the knowledge base from admin in a new tab', function (): void {
    /** @var KnowledgeBaseCompanionPlugin $plugin */
    $plugin = Filament::getPanel('admin')->getPlugin(KnowledgeBaseCompanionPlugin::ID);

    expect($plugin->shouldOpenKnowledgeBasePanelInNewTab())->toBeTrue();
});

it('renders an explicit back to admin link inside the knowledge base panel', function (): void {
    $contents = File::get(resource_path('views/filament/knowledge-base/back-to-admin.blade.php'));

    expect($contents)
        ->toContain('getDefaultPanel()->getUrl()')
        ->toContain('back-to-default-panel');
});

it('links key resources to documentation entries', function (): void {
    expect(ProductTypeResource::getDocumentation())->toBe(['getting-started/setup-order', 'reference-data/product-types'])
        ->and(ProductResource::getDocumentation())->toBe(['getting-started/setup-order', 'reference-data/products'])
        ->and(FormulaResource::getDocumentation())->toBe(['reference-data/formulas', 'reference-data/products'])
        ->and(IngredientResource::getDocumentation())->toBe(['reference-data/ingredients', 'procurement/suppliers-and-listings', 'stock-and-allocations/stock-lots'])
        ->and(SupplierListingResource::getDocumentation())->toBe(['procurement/suppliers-and-listings', 'procurement/supplier-orders'])
        ->and(ProductionWaveResource::getDocumentation())->toBe(['planning/production-waves', 'stock-and-allocations/allocations'])
        ->and(ProductionResource::getDocumentation())->toBe(['execution/productions', 'execution/tasks-qc-and-outputs', 'stock-and-allocations/allocations'])
        ->and(SupplierOrderResource::getDocumentation())->toBe(['procurement/supplier-orders', 'procurement/procurement-overview'])
        ->and(SupplyResource::getDocumentation())->toBe(['stock-and-allocations/stock-lots', 'stock-and-allocations/allocations']);
});

it('links key pages to documentation entries', function (): void {
    expect(PlanningBoard::getDocumentation())->toBe(['planning/planning-board', 'planning/production-waves', 'settings/production-lines'])
        ->and(SimulateurFlash::getDocumentation())->toBe(['planning/flash-simulator', 'planning/production-waves', 'getting-started/first-production-checklist'])
        ->and(WaveProcurementOverview::getDocumentation())->toBe(['procurement/procurement-overview', 'procurement/supplier-orders', 'stock-and-allocations/allocations']);
});

it('ships starter knowledge base files in all supported locales', function (): void {
    $expectedFiles = [
        'getting-started.md',
        'getting-started/setup-order.md',
        'reference-data/product-types.md',
        'reference-data/products.md',
        'procurement.md',
        'planning/production-waves.md',
        'stock-and-allocations/allocations.md',
    ];

    foreach (['fr', 'en', 'es'] as $locale) {
        foreach ($expectedFiles as $file) {
            expect(File::exists(base_path("docs/knowledge-base/{$locale}/{$file}")))->toBeTrue();
        }
    }
});

it('avoids duplicate top-level headings in starter knowledge base files', function (): void {
    foreach (['fr', 'en', 'es'] as $locale) {
        $contents = File::get(base_path("docs/knowledge-base/{$locale}/getting-started.md"));

        expect($contents)->not->toContain('# Introduction')
            ->not->toContain('# Introducción');
    }
});
