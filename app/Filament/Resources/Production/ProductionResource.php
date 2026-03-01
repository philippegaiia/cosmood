<?php

namespace App\Filament\Resources\Production;

use App\Filament\Resources\Production\ProductionResource\Pages\CreateProduction;
use App\Filament\Resources\Production\ProductionResource\Pages\EditProduction;
use App\Filament\Resources\Production\ProductionResource\Pages\ListProductions;
use App\Filament\Resources\Production\ProductionResource\Pages\ViewProduction;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionItemsRelationManager;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionQcChecksRelationManager;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionTasksRelationManager;
use App\Filament\Resources\Production\ProductionResource\Schemas\ProductionForm;
use App\Filament\Resources\Production\ProductionResource\Tables\ProductionsTable;
use App\Models\Production\Production;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Production resource definition.
 *
 * This resource manages productions (batches) in the cosmetics manufacturing system.
 * It delegates form configuration to ProductionForm and table configuration to
 * ProductionsTable, following Filament v5 best practices.
 *
 * Production items are managed via the ProductionItemsRelationManager for better
 * reactivity and separation of concerns.
 *
 * @see ProductionForm Form schema configuration
 * @see ProductionsTable Table configuration
 * @see ProductionItemsRelationManager Items management
 */
class ProductionResource extends Resource
{
    protected static ?string $model = Production::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Production';

    protected static ?string $navigationLabel = 'Productions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 10;

    /**
     * Configure the production form schema.
     *
     * Delegates to ProductionForm for all form configuration.
     *
     * @param  Schema  $schema  The schema instance to configure
     * @return Schema The configured schema
     */
    public static function form(Schema $schema): Schema
    {
        return ProductionForm::configure($schema);
    }

    /**
     * Configure the productions table.
     *
     * Delegates to ProductionsTable for all table configuration.
     *
     * @param  Table  $table  The table instance to configure
     * @return Table The configured table
     */
    public static function table(Table $table): Table
    {
        return ProductionsTable::configure($table);
    }

    /**
     * Get the relation managers for this resource.
     *
     * Returns the managers for production items, QC checks, and tasks.
     *
     * @return array<int, class-string> The relation manager classes
     */
    public static function getRelations(): array
    {
        return [
            ProductionItemsRelationManager::class,
            ProductionTasksRelationManager::class,
            ProductionQcChecksRelationManager::class,
        ];
    }

    /**
     * Get the pages for this resource.
     *
     * @return array<string, class-string> The page classes keyed by route name
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProductions::route('/'),
            'create' => CreateProduction::route('/create'),
            'view' => ViewProduction::route('/{record}'),
            'edit' => EditProduction::route('/{record}/edit'),
        ];
    }

    /**
     * Get the Eloquent query without soft deleting scope.
     *
     * This ensures trashed records are included in queries.
     *
     * @return Builder The modified query
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
