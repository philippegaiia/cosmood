<?php

namespace App\Filament\Resources\QcTemplates;

use App\Filament\Resources\QcTemplates\Pages\CreateQcTemplates;
use App\Filament\Resources\QcTemplates\Pages\EditQcTemplates;
use App\Filament\Resources\QcTemplates\Pages\ListQcTemplates;
use App\Filament\Resources\QcTemplates\Schemas\QcTemplatesForm;
use App\Filament\Resources\QcTemplates\Tables\QcTemplatesTable;
use App\Models\Production\QcTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class QcTemplatesResource extends Resource
{
    protected static ?string $model = QcTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Modèles QC';

    protected static string|\UnitEnum|null $navigationGroup = 'Production';

    protected static ?int $navigationSort = 55;

    public static function form(Schema $schema): Schema
    {
        return QcTemplatesForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QcTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQcTemplates::route('/'),
            'create' => CreateQcTemplates::route('/create'),
            'edit' => EditQcTemplates::route('/{record}/edit'),
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
