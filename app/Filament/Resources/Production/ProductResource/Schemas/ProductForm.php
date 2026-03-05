<?php

namespace App\Filament\Resources\Production\ProductResource\Schemas;

use App\Models\Production\Product;
use App\Models\Production\ProductType;
use App\Models\Supply\Ingredient;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Classification'))
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                Select::make('product_category_id')
                                    ->label(__('Catégorie'))
                                    ->relationship('productCategory', 'name')
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set): void {
                                        $set('product_type_id', null);
                                    })
                                    ->required(),
                                Select::make('product_type_id')
                                    ->label(__('Type de produit'))
                                    ->options(function (Get $get): array {
                                        return self::getProductTypeOptionsForCategory((int) ($get('product_category_id') ?? 0));
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->placeholder(function (Get $get): string {
                                        if (blank($get('product_category_id'))) {
                                            return __('Choisissez d\'abord une catégorie');
                                        }

                                        return __('Sélectionnez un type');
                                    })
                                    ->disabled(fn (Get $get): bool => blank($get('product_category_id')))
                                    ->required(),
                                Select::make('produced_ingredient_id')
                                    ->label(__('Ingrédient fabriqué'))
                                    ->relationship(
                                        name: 'producedIngredient',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_manufactured', true),
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->helperText(__('Si ce produit crée un ingrédient')),
                                TextInput::make('net_weight')
                                    ->label(__('Poids net (kg)'))
                                    ->numeric()
                                    ->step(0.001)
                                    ->required(),
                            ]),
                    ]),

                Section::make(__('Identité'))
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('Nom'))
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(2),
                                TextInput::make('code')
                                    ->label(__('Code'))
                                    ->maxLength(255),
                                TextInput::make('wp_code')
                                    ->label(__('SKU e-commerce'))
                                    ->maxLength(255),
                                TextInput::make('ean_code')
                                    ->label(__('Code EAN'))
                                    ->maxLength(255)
                                    ->columnSpan(2),
                                DatePicker::make('launch_date')
                                    ->label(__('Date de lancement'))
                                    ->native(false)
                                    ->required(),
                                Toggle::make('is_active')
                                    ->label(__('Actif'))
                                    ->onColor('success')
                                    ->offColor('warning')
                                    ->required(),
                            ]),
                    ]),

                Section::make(__('Formule & Packaging'))
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('default_formula_id')
                                    ->label(__('Formule par défaut'))
                                    ->options(function (?Product $record): array {
                                        if (! $record) {
                                            return [];
                                        }

                                        return $record->formulas()->pluck('formulas.name', 'formulas.id')->toArray();
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->native(false)
                                    ->helperText(__('Parmi les formules attachées'))
                                    ->afterStateHydrated(function (Set $set, ?Product $record): void {
                                        if ($record) {
                                            $set('default_formula_id', $record->defaultFormula()?->id);
                                        }
                                    })
                                    ->dehydrated(false),
                                Select::make('packaging_ids')
                                    ->label(__('Packaging'))
                                    ->multiple()
                                    ->options(Ingredient::query()->where('is_packaging', true)->pluck('name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->afterStateHydrated(function (Set $set, ?Product $record): void {
                                        if ($record) {
                                            $packagingIds = $record->packaging()->pluck('ingredients.id')->toArray();
                                            $set('packaging_ids', $packagingIds);
                                        }
                                    })
                                    ->dehydrated(false),
                            ]),
                    ]),

                Section::make(__('Description'))
                    ->columnSpanFull()
                    ->schema([
                        MarkdownEditor::make('description')
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    private static function getProductTypeOptionsForCategory(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        return ProductType::query()
            ->where('product_category_id', $categoryId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }
}
