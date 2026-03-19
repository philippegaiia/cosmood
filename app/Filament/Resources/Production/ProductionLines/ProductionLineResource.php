<?php

namespace App\Filament\Resources\Production\ProductionLines;

use App\Filament\Resources\Production\ProductionLines\Pages\CreateProductionLine;
use App\Filament\Resources\Production\ProductionLines\Pages\EditProductionLine;
use App\Filament\Resources\Production\ProductionLines\Pages\ListProductionLines;
use App\Filament\Resources\Production\ProductionLines\Schemas\ProductionLineForm;
use App\Filament\Resources\Production\ProductionLines\Tables\ProductionLinesTable;
use App\Models\Production\ProductionLine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

class ProductionLineResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = ProductionLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.configuration');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('navigation.items.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.production_lines');
    }

    public static function getModelLabel(): string
    {
        return __('Ligne de production');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.items.production_lines');
    }

    public static function form(Schema $schema): Schema
    {
        return ProductionLineForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductionLinesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductionLines::route('/'),
            'create' => CreateProductionLine::route('/create'),
            'edit' => EditProductionLine::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return [
            'settings/production-lines',
            'planning/planning-board',
        ];
    }
}
