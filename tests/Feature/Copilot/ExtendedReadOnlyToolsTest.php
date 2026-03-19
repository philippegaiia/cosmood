<?php

use App\Filament\Pages\CopilotTools\SimulateurFlash\ExplainFlashSimulatorTool;
use App\Filament\Pages\CopilotTools\WaveProcurementOverview\GetProcurementOverviewSummaryTool;
use App\Filament\Pages\SimulateurFlash;
use App\Filament\Pages\WaveProcurementOverview;
use App\Filament\Resources\Supply\IngredientResource;
use App\Filament\Resources\Supply\IngredientResource\CopilotTools\ListIngredientsTool;
use App\Filament\Resources\Supply\IngredientResource\CopilotTools\SearchIngredientsTool;
use App\Filament\Resources\Supply\IngredientResource\CopilotTools\ViewIngredientTool;
use App\Filament\Resources\Supply\SupplyResource;
use App\Filament\Resources\Supply\SupplyResource\CopilotTools\ListSuppliesTool;
use App\Filament\Resources\Supply\SupplyResource\CopilotTools\SearchSuppliesTool;
use App\Filament\Resources\Supply\SupplyResource\CopilotTools\ViewSupplyTool;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\Supply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

it('registers extended read-only copilot targets', function (): void {
    expect(SimulateurFlash::copilotPageDescription())->not->toBeNull()
        ->and(SimulateurFlash::copilotTools())->toHaveCount(1)
        ->and(WaveProcurementOverview::copilotPageDescription())->not->toBeNull()
        ->and(WaveProcurementOverview::copilotTools())->toHaveCount(1)
        ->and(IngredientResource::copilotResourceDescription())->not->toBeNull()
        ->and(IngredientResource::copilotTools())->toHaveCount(3)
        ->and(SupplyResource::copilotResourceDescription())->not->toBeNull()
        ->and(SupplyResource::copilotTools())->toHaveCount(3);
});

it('explains the flash simulator in read-only mode', function (): void {
    $result = app(ExplainFlashSimulatorTool::class)->handle(new Request([
        'focus' => 'conversion to wave',
    ]));

    expect((string) $result)
        ->toContain('Flash simulator summary')
        ->toContain('does not reserve stock')
        ->toContain('conversion to wave');
});

it('summarizes procurement overview in read-only mode', function (): void {
    $wave = ProductionWave::factory()->create(['name' => 'Wave Procurement']);
    SupplierOrder::factory()->forWave($wave)->create();

    $result = app(GetProcurementOverviewSummaryTool::class)->handle(new Request([
        'limit' => 3,
    ]));

    expect((string) $result)
        ->toContain('Procurement overview summary')
        ->toContain('Wave Procurement');
});

it('lists searches and views ingredients in read-only mode', function (): void {
    $ingredient = Ingredient::factory()->create([
        'code' => 'ACT999',
        'name' => 'Actif test',
    ]);

    $listed = app(ListIngredientsTool::class)->handle(new Request(['limit' => 5]));
    $searched = app(SearchIngredientsTool::class)->handle(new Request(['query' => 'ACT999']));
    $viewed = app(ViewIngredientTool::class)->handle(new Request(['identifier' => 'ACT999']));

    expect((string) $listed)->toContain('ACT999')
        ->and((string) $searched)->toContain('ACT999')
        ->and((string) $viewed)->toContain('Ingredient: '.$ingredient->name);
});

it('lists searches and views stock lots in read-only mode', function (): void {
    $supply = Supply::factory()->create([
        'batch_number' => 'LOT-READ-01',
        'order_ref' => 'PO-STOCK-01',
    ]);

    $listed = app(ListSuppliesTool::class)->handle(new Request(['limit' => 5]));
    $searched = app(SearchSuppliesTool::class)->handle(new Request(['query' => 'LOT-READ-01']));
    $viewed = app(ViewSupplyTool::class)->handle(new Request(['identifier' => 'LOT-READ-01']));

    expect((string) $listed)->toContain('LOT-READ-01')
        ->and((string) $searched)->toContain('LOT-READ-01')
        ->and((string) $viewed)->toContain('Batch number: LOT-READ-01')
        ->and((string) $viewed)->toContain((string) $supply->order_ref);
});
