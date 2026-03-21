<?php

declare(strict_types=1);

use App\Filament\Resources\Production\ProductionLines\CopilotTools\ListProductionLinesTool;
use App\Filament\Resources\Production\ProductionLines\CopilotTools\SearchProductionLinesTool;
use App\Filament\Resources\Production\ProductionLines\CopilotTools\ViewProductionLineTool;
use App\Filament\Resources\Production\ProductionLines\ProductionLineResource;
use App\Filament\Resources\QcTemplates\CopilotTools\ListQcTemplatesTool;
use App\Filament\Resources\QcTemplates\CopilotTools\SearchQcTemplatesTool;
use App\Filament\Resources\QcTemplates\CopilotTools\ViewQcTemplateTool;
use App\Filament\Resources\QcTemplates\QcTemplatesResource;
use App\Filament\Resources\Supply\SupplierResource;
use App\Filament\Resources\Supply\SupplierResource\CopilotTools\ListSuppliersTool;
use App\Filament\Resources\Supply\SupplierResource\CopilotTools\SearchSuppliersTool;
use App\Filament\Resources\Supply\SupplierResource\CopilotTools\ViewSupplierTool;
use App\Filament\Resources\TaskTemplates\CopilotTools\ListTaskTemplatesTool;
use App\Filament\Resources\TaskTemplates\CopilotTools\SearchTaskTemplatesTool;
use App\Filament\Resources\TaskTemplates\CopilotTools\ViewTaskTemplateTool;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Models\Production\ProductionLine;
use App\Models\Production\QcTemplate;
use App\Models\Production\TaskTemplate;
use App\Models\Supply\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

// Resource registration tests
it('registers configuration resources as read-only copilot targets', function () {
    expect(TaskTemplateResource::copilotResourceDescription())->not->toBeNull()
        ->and(TaskTemplateResource::copilotTools())->toHaveCount(3)
        ->and(QcTemplatesResource::copilotResourceDescription())->not->toBeNull()
        ->and(QcTemplatesResource::copilotTools())->toHaveCount(3)
        ->and(ProductionLineResource::copilotResourceDescription())->not->toBeNull()
        ->and(ProductionLineResource::copilotTools())->toHaveCount(3)
        ->and(SupplierResource::copilotResourceDescription())->not->toBeNull()
        ->and(SupplierResource::copilotTools())->toHaveCount(3);
});

// Task Template Tools
it('lists task templates with pagination', function () {
    TaskTemplate::factory()->count(5)->create();

    $result = app(ListTaskTemplatesTool::class)->handle(new Request(['limit' => 3]));

    expect((string) $result)
        ->toContain('product types')
        ->toContain('task types');
});

it('searches task templates by name', function () {
    TaskTemplate::factory()->create(['name' => 'Standard Soap Workflow']);
    TaskTemplate::factory()->create(['name' => 'Complex Serum Process']);

    $result = app(SearchTaskTemplatesTool::class)->handle(new Request(['query' => 'Soap', 'limit' => 10]));

    expect((string) $result)
        ->toContain('Standard Soap Workflow')
        ->not->toContain('Complex Serum Process');
});

it('views task template details', function () {
    $template = TaskTemplate::factory()->create(['name' => 'Test Template']);

    $result = app(ViewTaskTemplateTool::class)->handle(new Request(['id' => $template->id]));

    expect((string) $result)
        ->toContain('Test Template')
        ->toContain('Product Types')
        ->toContain('Tasks');
});

it('returns not found for invalid task template', function () {
    $result = app(ViewTaskTemplateTool::class)->handle(new Request(['id' => 99999]));

    expect((string) $result)->toBe(__('Task template not found'));
});

// QC Template Tools
it('lists QC templates with pagination', function () {
    QcTemplate::factory()->count(5)->create();

    $result = app(ListQcTemplatesTool::class)->handle(new Request(['limit' => 3]));

    expect((string) $result)
        ->toContain('active')
        ->toContain('product types');
});

it('searches QC templates by name', function () {
    QcTemplate::factory()->create(['name' => 'Soap QC Checklist']);
    QcTemplate::factory()->create(['name' => 'Serum QC Process']);

    $result = app(SearchQcTemplatesTool::class)->handle(new Request(['query' => 'Soap', 'limit' => 10]));

    expect((string) $result)
        ->toContain('Soap QC Checklist')
        ->not->toContain('Serum QC Process');
});

it('views QC template details', function () {
    $template = QcTemplate::factory()->create(['name' => 'Test QC Template']);

    $result = app(ViewQcTemplateTool::class)->handle(new Request(['id' => $template->id]));

    expect((string) $result)
        ->toContain('Test QC Template')
        ->toContain('Product Types')
        ->toContain('Items');
});

// Production Line Tools
it('lists production lines with pagination', function () {
    ProductionLine::factory()->count(5)->create();

    $result = app(ListProductionLinesTool::class)->handle(new Request(['limit' => 3]));

    expect((string) $result)
        ->toContain('Capacity')
        ->toContain('active');
});

it('filters production lines by active status', function () {
    ProductionLine::factory()->create(['name' => 'Active Line', 'is_active' => true]);
    ProductionLine::factory()->create(['name' => 'Inactive Line', 'is_active' => false]);

    $result = app(ListProductionLinesTool::class)->handle(new Request(['limit' => 10, 'active_only' => true]));

    expect((string) $result)
        ->toContain('Active Line')
        ->not->toContain('Inactive Line');
});

it('searches production lines by name', function () {
    ProductionLine::factory()->create(['name' => 'Main Production Line']);
    ProductionLine::factory()->create(['name' => 'Secondary Line']);

    $result = app(SearchProductionLinesTool::class)->handle(new Request(['query' => 'Main', 'limit' => 10]));

    expect((string) $result)
        ->toContain('Main Production Line')
        ->not->toContain('Secondary Line');
});

it('views production line details', function () {
    $line = ProductionLine::factory()->create(['name' => 'Test Production Line']);

    $result = app(ViewProductionLineTool::class)->handle(new Request(['id' => $line->id]));

    expect((string) $result)
        ->toContain('Test Production Line')
        ->toContain('Daily Capacity')
        ->toContain('Product Types');
});

// Supplier Tools
it('lists suppliers with pagination', function () {
    Supplier::factory()->count(5)->create();

    $result = app(ListSuppliersTool::class)->handle(new Request(['limit' => 3]));

    expect((string) $result)
        ->toContain('days delivery')
        ->toContain('contacts');
});

it('filters suppliers by active status', function () {
    Supplier::factory()->create(['name' => 'Active Supplier', 'is_active' => true]);
    Supplier::factory()->create(['name' => 'Inactive Supplier', 'is_active' => false]);

    $result = app(ListSuppliersTool::class)->handle(new Request(['limit' => 10, 'active_only' => true]));

    expect((string) $result)
        ->toContain('Active Supplier')
        ->not->toContain('Inactive Supplier');
});

it('searches suppliers by name', function () {
    Supplier::factory()->create(['name' => 'Chemicals Inc', 'code' => 'CHE']);
    Supplier::factory()->create(['name' => 'Packaging Pro', 'code' => 'PAC']);

    $result = app(SearchSuppliersTool::class)->handle(new Request(['query' => 'Chemical', 'limit' => 10]));

    expect((string) $result)
        ->toContain('Chemicals Inc')
        ->not->toContain('Packaging Pro');
});

it('searches suppliers by code', function () {
    Supplier::factory()->create(['name' => 'Chemicals Inc', 'code' => 'CHE']);
    Supplier::factory()->create(['name' => 'Packaging Pro', 'code' => 'PAC']);

    $result = app(SearchSuppliersTool::class)->handle(new Request(['query' => 'PAC', 'limit' => 10]));

    expect((string) $result)
        ->toContain('Packaging Pro')
        ->not->toContain('Chemicals Inc');
});

it('views supplier details', function () {
    $supplier = Supplier::factory()->create([
        'name' => 'Test Supplier',
        'code' => 'TST',
        'city' => 'Paris',
    ]);

    $result = app(ViewSupplierTool::class)->handle(new Request(['id' => $supplier->id]));

    expect((string) $result)
        ->toContain('Test Supplier')
        ->toContain('TST')
        ->toContain('Paris')
        ->toContain('Contacts')
        ->toContain('Listings');
});
