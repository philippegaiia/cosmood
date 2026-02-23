<?php

namespace App\Filament\Resources\Supply;

use App\Enums\Packaging;
use App\Filament\Resources\Supply\SupplierListingResource\Pages\CreateSupplierListing;
use App\Filament\Resources\Supply\SupplierListingResource\Pages\EditSupplierListing;
use App\Filament\Resources\Supply\SupplierListingResource\Pages\ListSupplierListings;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SupplierListingResource extends Resource
{
    protected static ?string $model = SupplierListing::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Achats';

    protected static ?string $navigationLabel = 'Ingrédients référencés';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

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
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->maxLength(255),
                TextInput::make('supplier_code')
                    ->maxLength(255),
                Select::make('pkg')
                    ->options(Packaging::class),
                Select::make('pkg')
                    ->options(Packaging::class),
                Select::make('unit_of_measure')
                    ->options([
                        'kg' => 'kg',
                        'g' => 'Gramme',
                        'Unit' => 'Unité',
                        'Meter' => 'Mètre',
                        'Litre' => 'Litre',
                    ])
                    ->default('Kilo.'),
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
                    ->required(),
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
            'index' => ListSupplierListings::route('/'),
            'create' => CreateSupplierListing::route('/create'),
            'edit' => EditSupplierListing::route('/{record}/edit'),
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
