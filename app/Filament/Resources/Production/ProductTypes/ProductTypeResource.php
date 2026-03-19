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
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

class ProductTypeResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = ProductType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare3Stack3d;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.references');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.product_types');
    }

    public static function getModelLabel(): string
    {
        return __('resources.product_types.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('resources.product_types.plural');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('navigation.items.products');
    }

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

    public static function getDocumentation(): array|string
    {
        return [
            'getting-started/setup-order',
            'reference-data/product-types',
        ];
    }
}
