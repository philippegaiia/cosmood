<?php

namespace App\Filament\Resources\Production\ProductionWaves;

use App\Filament\Resources\Production\ProductionWaves\CopilotTools\ListProductionWavesTool;
use App\Filament\Resources\Production\ProductionWaves\CopilotTools\SearchProductionWavesTool;
use App\Filament\Resources\Production\ProductionWaves\CopilotTools\ViewProductionWaveTool;
use App\Filament\Resources\Production\ProductionWaves\Pages\CreateProductionWave;
use App\Filament\Resources\Production\ProductionWaves\Pages\EditProductionWave;
use App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves;
use App\Filament\Resources\Production\ProductionWaves\RelationManagers\ProductionsRelationManager;
use App\Filament\Resources\Production\ProductionWaves\Schemas\ProductionWaveForm;
use App\Filament\Resources\Production\ProductionWaves\Tables\ProductionWavesTable;
use App\Models\Production\ProductionWave;
use BackedEnum;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

class ProductionWaveResource extends Resource implements CopilotResource, HasKnowledgeBase
{
    protected static ?string $model = ProductionWave::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.production_waves');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('navigation.items.productions');
    }

    public static function form(Schema $schema): Schema
    {
        return ProductionWaveForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductionWavesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProductionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductionWaves::route('/'),
            'create' => CreateProductionWave::route('/create'),
            'edit' => EditProductionWave::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return [
            'planning/production-waves',
            'stock-and-allocations/allocations',
        ];
    }

    public static function copilotResourceDescription(): ?string
    {
        return 'Read-only access to production waves, including their status, dates, linked productions, and planning context.';
    }

    public static function copilotTools(): array
    {
        return [
            new ListProductionWavesTool,
            new SearchProductionWavesTool,
            new ViewProductionWaveTool,
        ];
    }
}
