<?php

namespace App\Filament\Resources\Production;

use App\Filament\Resources\Production\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\Production\ProductResource\Pages\EditProduct;
use App\Filament\Resources\Production\ProductResource\Pages\ListProducts;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\ProductType;
use App\Models\Supply\Ingredient;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Produits';

    protected static ?string $navigationLabel = 'Produits';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_category_id')
                    ->label('Catégorie')
                    ->relationship('productCategory', 'name')
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('product_type_id', null);
                    })
                    ->helperText('La catégorie détermine les types disponibles.')
                    ->required(),

                Select::make('product_type_id')
                    ->label('Type de produit')
                    ->options(function (Get $get): array {
                        return self::getProductTypeOptionsForCategory((int) ($get('product_category_id') ?? 0));
                    })
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->placeholder(function (Get $get): string {
                        if (blank($get('product_category_id'))) {
                            return 'Choisissez d\'abord une catégorie';
                        }

                        return 'Sélectionnez un type';
                    })
                    ->helperText(function (Get $get): ?string {
                        $categoryId = (int) ($get('product_category_id') ?? 0);

                        if ($categoryId <= 0) {
                            return 'Sélectionnez la catégorie avant le type.';
                        }

                        if (self::getProductTypeOptionsForCategory($categoryId) === []) {
                            return 'Aucun type défini pour cette catégorie. Créez-le dans Types de Produit.';
                        }

                        return 'Le type pilote les presets de batch et le modèle QC.';
                    })
                    ->disabled(fn (Get $get): bool => blank($get('product_category_id')))
                    ->required(),

                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->maxLength(255),
                TextInput::make('wp_code')
                    ->maxLength(255),
                Select::make('produced_ingredient_id')
                    ->label('Ingrédient fabriqué lié')
                    ->relationship(
                        name: 'producedIngredient',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_manufactured', true),
                    )
                    ->searchable()
                    ->preload()
                    ->nullable(),

                DatePicker::make('launch_date')
                    ->native(false)
                    ->required(),
                TextInput::make('net_weight')
                    ->required()
                    ->numeric(),
                TextInput::make('ean_code')
                    ->maxLength(255),
                MarkdownEditor::make('description')
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->onColor('success')
                    ->offColor('warning')
                    ->required(),

                Select::make('default_formula_id')
                    ->label('Formule par défaut')
                    ->options(Formula::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->native(false)
                    ->helperText('Sélectionnez la formule principale pour ce produit. Laissez vide si aucune formule n\'est encore définie.')
                    ->afterStateHydrated(function (Set $set, ?Product $record): void {
                        if ($record) {
                            $set('default_formula_id', $record->defaultFormula()?->id);
                        }
                    })
                    ->dehydrated(false),

                // Packaging
                Repeater::make('productPackagingItems')
                    ->label('Packaging')
                    ->relationship()
                    ->schema([
                        Select::make('ingredient_id')
                            ->label('Élément de packaging')
                            ->options(Ingredient::where('is_active', true)->where('is_packaging', true)->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),
                        TextInput::make('quantity_per_unit')
                            ->label('Quantité par unité')
                            ->numeric()
                            ->default(1)
                            ->required(),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Ajouter un élément de packaging'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_category.name')
                    ->sortable(),
                TextColumn::make('productType.name')
                    ->label('Type')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('formula_name')
                    ->label('Formule par défaut')
                    ->state(fn (Product $record): ?string => $record->defaultFormula()?->name)
                    ->placeholder('-'),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('producedIngredient.name')
                    ->label('Ingrédient fabriqué')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('wp_code')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('launch_date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('net_weight')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('ean_code')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_active')
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                ReplicateAction::make()->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
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
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['formulas'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
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
