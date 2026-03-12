<?php

namespace App\Filament\Resources\Supply;

use App\Enums\IngredientBaseUnit;
use App\Enums\Packaging;
use App\Filament\Resources\Supply\SupplierListingResource\Pages\CreateSupplierListing;
use App\Filament\Resources\Supply\SupplierListingResource\Pages\EditSupplierListing;
use App\Filament\Resources\Supply\SupplierListingResource\Pages\ListSupplierListings;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupplierListingResource extends Resource
{
    protected static ?string $model = SupplierListing::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Achats';

    protected static ?string $navigationLabel = 'Ingrédients référencés';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('supplier_id')
                    ->relationship('supplier', 'name')
                    ->options(Supplier::all()->pluck('name', 'id'))
                    ->preload()
                    ->searchable()
                    ->required(),
                Select::make('ingredient_id')
                    ->relationship('ingredient', 'name')
                    ->options(Ingredient::all()->pluck('name', 'id'))
                    ->preload()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if (! $state) {
                            return;
                        }

                        $ingredient = Ingredient::query()->find((int) $state);

                        if (! $ingredient) {
                            return;
                        }

                        $set(
                            'unit_of_measure',
                            ($ingredient->base_unit?->value ?? IngredientBaseUnit::Kg->value) === IngredientBaseUnit::Unit->value
                                ? 'Unit'
                                : 'kg'
                        );
                    })
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->maxLength(255),
                TextInput::make('supplier_code')
                    ->maxLength(255),
                Select::make('pkg')
                    ->label('Conditionnement')
                    ->options(Packaging::class)
                    ->default(Packaging::Bidon->value),
                TextInput::make('unit_weight')
                    ->numeric()
                    ->inputMode('decimal'),
                Select::make('unit_of_measure')
                    ->options([
                        'kg' => 'kg',
                        'g' => 'Gramme',
                        'Unit' => 'Unité',
                        'Meter' => 'Mètre',
                        'Litre' => 'Litre',
                    ])
                    ->default('kg'),
                TextInput::make('price')
                    ->numeric()
                    ->prefix('€'),
                Toggle::make('organic')
                    ->required(),
                Toggle::make('fairtrade')
                    ->required(),
                Toggle::make('cosmos')
                    ->required(),
                Toggle::make('ecocert')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required()
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->striped()
            // ->deferLoading()
            ->columns([

                TextColumn::make('name')
                    ->label('designation')
                    ->formatStateUsing(fn ($record) => $record->name.' '.$record->unit_weight.' '.$record->unit_of_measure)
                    ->weight(FontWeight::Bold)
                    ->searchable(['name', 'unit_of_measure']),

                TextColumn::make('code')
                    ->searchable(),

                TextColumn::make('ingredient.name')
                    // ->numeric()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->numeric()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('pkg')
                    ->label('Packaging')

                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('unit_weight')
                    ->label('Poids Unit.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('price')
                    ->label('Prix')
                    ->money('EUR')
                    ->sortable(),
                IconColumn::make('organic')
                    ->label('Bio')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('fairtrade')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('cosmos')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('ecocert')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),
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
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    ViewAction::make(),
                    DeleteAction::make()
                        ->action(function ($data, $record) {
                            if ($record->supplies()->count() > 0 || $record->supplier_order_items()->count() > 0) {
                                Notification::make()
                                    ->danger()
                                    ->title('Opération Impossible')
                                    ->body('Cet ingrédient est référencé dans des commandes fournisseur et dans les stocks ingrédients.')
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title('Fournisseur Supprimé')
                                ->body('Ingrédient '.$record->name.' supprimé avec succès.')
                                ->send();

                            $record->delete();
                        }),
                ]),
            ])
            ->toolbarActions([
                //
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
            'index' => ListSupplierListings::route('/'),
            'create' => CreateSupplierListing::route('/create'),
            'edit' => EditSupplierListing::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
}
