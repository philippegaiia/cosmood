<?php

namespace App\Filament\Resources\Production;

use App\Filament\Resources\Production\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\Production\ProductResource\Pages\EditProduct;
use App\Filament\Resources\Production\ProductResource\Pages\ListProducts;
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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
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
                Tabs::make('ProductTabs')
                    ->tabs([
                        Tab::make('Détails')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        // Column 1: Classification
                                        Section::make('Classification')
                                            ->schema([
                                                Select::make('product_category_id')
                                                    ->label('Catégorie')
                                                    ->relationship('productCategory', 'name')
                                                    ->native(false)
                                                    ->live()
                                                    ->afterStateUpdated(fn (Set $set) => $set('product_type_id', null))
                                                    ->required(),

                                                Select::make('product_type_id')
                                                    ->label('Type de produit')
                                                    ->options(fn (Get $get): array => self::getProductTypeOptionsForCategory((int) ($get('product_category_id') ?? 0)))
                                                    ->searchable()
                                                    ->preload()
                                                    ->native(false)
                                                    ->placeholder(fn (Get $get): string => blank($get('product_category_id')) ? 'Choisissez d\'abord une catégorie' : 'Sélectionnez un type')
                                                    ->disabled(fn (Get $get): bool => blank($get('product_category_id')))
                                                    ->required(),
                                            ])
                                            ->columnSpan(1),

                                        // Column 2: Identité
                                        Section::make('Identité')
                                            ->schema([
                                                TextInput::make('name')
                                                    ->label('Nom')
                                                    ->required()
                                                    ->maxLength(255)
                                                    ->columnSpanFull(),

                                                Grid::make(2)
                                                    ->schema([
                                                        TextInput::make('code')
                                                            ->label('Code')
                                                            ->maxLength(255),

                                                        TextInput::make('wp_code')
                                                            ->label('Code WP')
                                                            ->maxLength(255),
                                                    ]),
                                            ])
                                            ->columnSpan(1),

                                        // Column 3: Statut
                                        Section::make('Statut')
                                            ->schema([
                                                Toggle::make('is_active')
                                                    ->label('Actif')
                                                    ->onColor('success')
                                                    ->offColor('warning')
                                                    ->default(true),

                                                DatePicker::make('launch_date')
                                                    ->label('Date de lancement')
                                                    ->native(false)
                                                    ->required(),
                                            ])
                                            ->columnSpan(1),
                                    ]),

                                Grid::make(3)
                                    ->schema([
                                        // Column 1: Formula & Packaging
                                        Section::make('Formule & Packaging')
                                            ->schema([
                                                Select::make('default_formula_id')
                                                    ->label('Formule par défaut')
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
                                                    ->helperText('Parmi les formules attachées')
                                                    ->afterStateHydrated(function (Set $set, ?Product $record): void {
                                                        if ($record) {
                                                            $set('default_formula_id', $record->defaultFormula()?->id);
                                                        }
                                                    })
                                                    ->dehydrated(false),

                                                Select::make('packaging_ids')
                                                    ->label('Packaging')
                                                    ->multiple()
                                                    ->options(Ingredient::where('is_packaging', true)->pluck('name', 'id'))
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
                                            ])
                                            ->columnSpan(1),

                                        // Column 2: Poids & Dimensions
                                        Section::make('Poids & Dimensions')
                                            ->schema([
                                                TextInput::make('net_weight')
                                                    ->label('Poids net (kg)')
                                                    ->numeric()
                                                    ->step(0.001)
                                                    ->required(),

                                                TextInput::make('ean_code')
                                                    ->label('Code EAN')
                                                    ->maxLength(255),
                                            ])
                                            ->columnSpan(1),

                                        // Column 3: Ingrédient fabriqué
                                        Section::make('Production')
                                            ->schema([
                                                Select::make('produced_ingredient_id')
                                                    ->label('Ingrédient fabriqué')
                                                    ->relationship(
                                                        name: 'producedIngredient',
                                                        titleAttribute: 'name',
                                                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_manufactured', true),
                                                    )
                                                    ->searchable()
                                                    ->preload()
                                                    ->nullable()
                                                    ->helperText('Si ce produit crée un ingrédient'),
                                            ])
                                            ->columnSpan(1),
                                    ]),

                                MarkdownEditor::make('description')
                                    ->label('Description')
                                    ->maxLength(65535)
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Productions')
                            ->schema([
                                \Filament\Forms\Components\Placeholder::make('productions_info')
                                    ->label('Productions liées')
                                    ->content('Les productions de ce produit apparaîtront ici.'),
                            ])
                            ->visibleOn('edit'),
                    ])
                    ->persistTabInQueryString(),
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
