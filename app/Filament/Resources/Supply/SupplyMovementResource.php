<?php

namespace App\Filament\Resources\Supply;

use App\Filament\Resources\Supply\SupplyMovementResource\Pages\ListSupplyMovements;
use App\Filament\Resources\Supply\SupplyMovementResource\Tables\SuppliesMovementsTable;
use App\Models\Supply\SuppliesMovement;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Supply Movements Resource.
 *
 * Read-only resource for viewing supply movement history.
 * Delegates table configuration to SuppliesMovementsTable.
 * Default view: last 3 months.
 */
class SupplyMovementResource extends Resource
{
    protected static ?string $model = SuppliesMovement::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Achats';

    protected static ?string $navigationLabel = 'Mouvements stock';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-c-arrows-right-left';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    /**
     * Configure the supply movements table.
     *
     * Delegates to SuppliesMovementsTable for all table configuration.
     *
     * @param  Table  $table  The table instance to configure
     * @return Table The configured table
     */
    public static function table(Table $table): Table
    {
        return SuppliesMovementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplyMovements::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
