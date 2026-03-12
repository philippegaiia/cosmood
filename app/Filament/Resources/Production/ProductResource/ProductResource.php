<?php

namespace App\Filament\Resources\Production\ProductResource;

use App\Filament\Resources\Production\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\Production\ProductResource\Pages\EditProduct;
use App\Filament\Resources\Production\ProductResource\Pages\ListProducts;
use App\Filament\Resources\Production\ProductResource\RelationManagers\ProductionsRelationManager;
use App\Filament\Resources\Production\ProductResource\Schemas\ProductForm;
use App\Filament\Resources\Production\ProductResource\Tables\ProductsTable;
use App\Models\Production\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $slug = 'production/products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationGroup(): ?string
    {
        return __('Produits');
    }

    public static function getNavigationLabel(): string
    {
        return __('Produits');
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
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
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['formulas']);
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->hasProductionHistory();
    }
}
