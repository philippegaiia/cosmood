<?php

namespace App\Filament\Resources\Production\ProductResource;

use App\Filament\Resources\Production\ProductResource\CopilotTools\ListProductsTool;
use App\Filament\Resources\Production\ProductResource\CopilotTools\SearchProductsTool;
use App\Filament\Resources\Production\ProductResource\CopilotTools\ViewProductTool;
use App\Filament\Resources\Production\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\Production\ProductResource\Pages\EditProduct;
use App\Filament\Resources\Production\ProductResource\Pages\ListProducts;
use App\Filament\Resources\Production\ProductResource\RelationManagers\ProductionsRelationManager;
use App\Filament\Resources\Production\ProductResource\Schemas\ProductForm;
use App\Filament\Resources\Production\ProductResource\Tables\ProductsTable;
use App\Models\Production\Product;
use BackedEnum;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends Resource implements CopilotResource, HasKnowledgeBase
{
    protected static ?string $model = Product::class;

    protected static ?string $slug = 'production/products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 0;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.references');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.products');
    }

    public static function getModelLabel(): string
    {
        return __('resources.products.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('resources.products.plural');
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

    public static function getDocumentation(): array|string
    {
        return [
            'getting-started/setup-order',
            'reference-data/products',
        ];
    }

    public static function copilotResourceDescription(): ?string
    {
        return 'Read-only access to products, including their product type, launch date, default formula, packaging linkage, and active status.';
    }

    public static function copilotTools(): array
    {
        return [
            new ListProductsTool,
            new SearchProductsTool,
            new ViewProductTool,
        ];
    }
}
