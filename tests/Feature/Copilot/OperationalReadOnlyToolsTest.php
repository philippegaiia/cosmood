<?php

use App\Filament\Pages\CopilotTools\PlanningBoard\GetPlanningBoardSummaryTool;
use App\Filament\Pages\PlanningBoard;
use App\Filament\Resources\Production\ProductionResource;
use App\Filament\Resources\Production\ProductionResource\CopilotTools\ListProductionsTool;
use App\Filament\Resources\Production\ProductionResource\CopilotTools\SearchProductionsTool;
use App\Filament\Resources\Production\ProductionResource\CopilotTools\ViewProductionTool;
use App\Filament\Resources\Production\ProductionWaves\CopilotTools\ListProductionWavesTool;
use App\Filament\Resources\Production\ProductionWaves\CopilotTools\SearchProductionWavesTool;
use App\Filament\Resources\Production\ProductionWaves\CopilotTools\ViewProductionWaveTool;
use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use App\Filament\Resources\Supply\SupplierOrderResource;
use App\Filament\Resources\Supply\SupplierOrderResource\CopilotTools\ListSupplierOrdersTool;
use App\Filament\Resources\Supply\SupplierOrderResource\CopilotTools\SearchSupplierOrdersTool;
use App\Filament\Resources\Supply\SupplierOrderResource\CopilotTools\ViewSupplierOrderTool;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionWave;
use App\Models\Supply\SupplierOrder;
use Database\Factories\Production\ProductTypeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

it('registers the four requested areas as read-only copilot targets', function (): void {
    expect(PlanningBoard::copilotPageDescription())->not->toBeNull()
        ->and(PlanningBoard::copilotTools())->toHaveCount(1)
        ->and(ProductionWaveResource::copilotResourceDescription())->not->toBeNull()
        ->and(ProductionWaveResource::copilotTools())->toHaveCount(3)
        ->and(SupplierOrderResource::copilotResourceDescription())->not->toBeNull()
        ->and(SupplierOrderResource::copilotTools())->toHaveCount(3)
        ->and(ProductionResource::copilotResourceDescription())->not->toBeNull()
        ->and(ProductionResource::copilotTools())->toHaveCount(3);
});

it('summarizes the planning board in read-only mode', function (): void {
    $line = ProductionLine::factory()->create(['name' => 'Main line']);
    $productType = ProductTypeFactory::new()->withDefaultProductionLine($line)->create();
    $product = Product::factory()->withProductType($productType)->create(['name' => 'Savon test']);

    Production::factory()->withProductType($productType)->create([
        'product_id' => $product->id,
        'production_line_id' => $line->id,
        'batch_number' => 'T10001',
        'production_date' => now()->addDays(2)->toDateString(),
    ]);

    $result = app(GetPlanningBoardSummaryTool::class)->handle(new Request(['days' => 7]));

    expect((string) $result)
        ->toContain('Planning summary for the next 7 days')
        ->toContain('Main line')
        ->toContain('T10001');
});

it('lists searches and views production waves in read-only mode', function (): void {
    $wave = ProductionWave::factory()->create([
        'name' => 'Spring Wave',
        'slug' => 'spring-wave',
    ]);

    $listed = app(ListProductionWavesTool::class)->handle(new Request(['limit' => 5]));
    $searched = app(SearchProductionWavesTool::class)->handle(new Request(['query' => 'Spring']));
    $viewed = app(ViewProductionWaveTool::class)->handle(new Request(['identifier' => 'spring-wave']));

    expect((string) $listed)->toContain('Spring Wave')
        ->and((string) $searched)->toContain('Spring Wave')
        ->and((string) $viewed)->toContain('Wave: Spring Wave');
});

it('lists searches and views supplier orders in read-only mode', function (): void {
    $order = SupplierOrder::factory()->create([
        'order_ref' => 'PO-READONLY-01',
    ]);

    $listed = app(ListSupplierOrdersTool::class)->handle(new Request(['limit' => 5]));
    $searched = app(SearchSupplierOrdersTool::class)->handle(new Request(['query' => 'READONLY']));
    $viewed = app(ViewSupplierOrderTool::class)->handle(new Request(['identifier' => 'PO-READONLY-01']));

    expect((string) $listed)->toContain('PO-READONLY-01')
        ->and((string) $searched)->toContain('PO-READONLY-01')
        ->and((string) $viewed)->toContain('Order: PO-READONLY-01');
});

it('lists searches and views productions in read-only mode', function (): void {
    $line = ProductionLine::factory()->create(['name' => 'Line A']);
    $productType = ProductTypeFactory::new()->withDefaultProductionLine($line)->create();
    $product = Product::factory()->withProductType($productType)->create(['name' => 'Baume test']);

    $production = Production::factory()->withProductType($productType)->create([
        'product_id' => $product->id,
        'production_line_id' => $line->id,
        'batch_number' => 'T20002',
    ]);

    $listed = app(ListProductionsTool::class)->handle(new Request(['limit' => 5]));
    $searched = app(SearchProductionsTool::class)->handle(new Request(['query' => 'T20002']));
    $viewed = app(ViewProductionTool::class)->handle(new Request(['identifier' => 'T20002']));

    expect((string) $listed)->toContain('T20002')
        ->and((string) $searched)->toContain('T20002')
        ->and((string) $viewed)->toContain('Batch: T20002')
        ->and((string) $viewed)->toContain((string) $production->status->value);
});
