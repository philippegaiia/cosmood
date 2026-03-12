<?php

namespace App\Filament\Resources\Supply\StockMovements;

use App\Filament\Resources\Supply\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\Supply\StockMovements\Tables\StockMovementsTable;
use App\Models\Supply\SuppliesMovement;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock Movement Resource.
 *
 * Read-only resource for viewing stock movement history.
 * Only visible to admin users.
 */
class StockMovementResource extends Resource
{
    protected static ?string $model = SuppliesMovement::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Achats';

    protected static ?string $navigationLabel = 'Mouvements de stock';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAccessStockMovements() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }
}
