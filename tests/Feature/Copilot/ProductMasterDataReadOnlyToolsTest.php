<?php

use App\Filament\Resources\Production\FormulaResource;
use App\Filament\Resources\Production\FormulaResource\CopilotTools\ListFormulasTool;
use App\Filament\Resources\Production\FormulaResource\CopilotTools\SearchFormulasTool;
use App\Filament\Resources\Production\FormulaResource\CopilotTools\ViewFormulaTool;
use App\Filament\Resources\Production\ProductResource\CopilotTools\ListProductsTool;
use App\Filament\Resources\Production\ProductResource\CopilotTools\SearchProductsTool;
use App\Filament\Resources\Production\ProductResource\CopilotTools\ViewProductTool;
use App\Filament\Resources\Production\ProductResource\ProductResource;
use App\Filament\Resources\Production\ProductTypes\CopilotTools\ListProductTypesTool;
use App\Filament\Resources\Production\ProductTypes\CopilotTools\SearchProductTypesTool;
use App\Filament\Resources\Production\ProductTypes\CopilotTools\ViewProductTypeTool;
use App\Filament\Resources\Production\ProductTypes\ProductTypeResource;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use Database\Factories\Production\FormulaFactory;
use Database\Factories\Production\ProductTypeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

it('registers product master data as read-only copilot resources', function (): void {
    expect(ProductTypeResource::copilotResourceDescription())->not->toBeNull()
        ->and(ProductTypeResource::copilotTools())->toHaveCount(3)
        ->and(ProductResource::copilotResourceDescription())->not->toBeNull()
        ->and(ProductResource::copilotTools())->toHaveCount(3)
        ->and(FormulaResource::copilotResourceDescription())->not->toBeNull()
        ->and(FormulaResource::copilotTools())->toHaveCount(3);
});

it('lists searches and views product types in read-only mode', function (): void {
    $type = ProductTypeFactory::new()->create([
        'name' => 'Savon barre',
        'slug' => 'savon-barre',
    ]);

    $listed = app(ListProductTypesTool::class)->handle(new Request(['limit' => 5]));
    $searched = app(SearchProductTypesTool::class)->handle(new Request(['query' => 'savon']));
    $viewed = app(ViewProductTypeTool::class)->handle(new Request(['identifier' => 'savon-barre']));

    expect((string) $listed)->toContain('Savon barre')
        ->and((string) $searched)->toContain('Savon barre')
        ->and((string) $viewed)->toContain('Product type: Savon barre')
        ->and((string) $viewed)->toContain((string) $type->expected_units_output);
});

it('lists searches and views products in read-only mode', function (): void {
    $type = ProductTypeFactory::new()->create();
    $product = Product::factory()->withProductType($type)->create([
        'code' => 'PRD-777',
        'name' => 'Baume douceur',
    ]);

    $listed = app(ListProductsTool::class)->handle(new Request(['limit' => 5]));
    $searched = app(SearchProductsTool::class)->handle(new Request(['query' => 'PRD-777']));
    $viewed = app(ViewProductTool::class)->handle(new Request(['identifier' => 'PRD-777']));

    expect((string) $listed)->toContain('PRD-777')
        ->and((string) $searched)->toContain('PRD-777')
        ->and((string) $viewed)->toContain('Product: Baume douceur')
        ->and((string) $viewed)->toContain('Code: PRD-777')
        ->and((string) $viewed)->toContain((string) $product->productType?->name);
});

it('lists searches and views formulas in read-only mode', function (): void {
    $formula = FormulaFactory::new()->create([
        'name' => 'Formule apaisante',
        'code' => 'FRM-7777',
    ]);

    $listed = app(ListFormulasTool::class)->handle(new Request(['limit' => 5]));
    $searched = app(SearchFormulasTool::class)->handle(new Request(['query' => 'FRM-7777']));
    $viewed = app(ViewFormulaTool::class)->handle(new Request(['identifier' => 'FRM-7777']));

    expect((string) $listed)->toContain('FRM-7777')
        ->and((string) $searched)->toContain('FRM-7777')
        ->and((string) $viewed)->toContain('Formula: Formule apaisante')
        ->and((string) $viewed)->toContain('Code: FRM-7777')
        ->and((string) $viewed)->toContain((string) Formula::query()->whereKey($formula->id)->firstOrFail()->formulaItems()->count());
});
