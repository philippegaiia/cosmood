<?php

namespace App\Filament\Resources\Production\ProductTypes;

use App\Filament\Resources\Production\ProductTypes\Pages\CreateProductType;
use App\Filament\Resources\Production\ProductTypes\Pages\EditProductType;
use App\Filament\Resources\Production\ProductTypes\Pages\ListProductTypes;
use App\Filament\Resources\Production\ProductTypes\Schemas\ProductTypeForm;
use App\Filament\Resources\Production\ProductTypes\Tables\ProductTypesTable;
use App\Models\Production\ProductType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductTypeResource extends Resource
{
    protected static ?string $model = ProductType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare3Stack3d;

    protected static ?string $navigationLabel = 'Types de Produit';

    protected static string|\UnitEnum|null $navigationGroup = 'Produits';

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return ProductTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductTypes::route('/'),
            'create' => CreateProductType::route('/create'),
            'edit' => EditProductType::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
