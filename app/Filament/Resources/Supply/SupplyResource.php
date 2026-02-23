<?php

namespace App\Filament\Resources\Supply;

use App\Filament\Resources\Supply\SupplyResource\Pages\CreateSupply;
use App\Filament\Resources\Supply\SupplyResource\Pages\EditSupply;
use App\Filament\Resources\Supply\SupplyResource\Pages\ListSupplies;
use App\Filament\Resources\Supply\SupplyResource\Pages\ViewSupply;
use App\Models\Supply\Supply;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SupplyResource extends Resource
{
    protected static ?string $model = Supply::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Achats';

    protected static ?string $navigationLabel = 'Inventaire';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-c-book-open';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('supplier_listing_id')
                    ->label('Ingrédient')
                    ->relationship('supplierListing', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Model $record) => "{$record->name} {$record->unit_weight}kg {$record->unit_of_measure} - {$record->supplier->name}")
                    ->native(false)
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('order_ref')
                    ->maxLength(255),

                TextInput::make('batch_number')
                    ->maxLength(255),

                TextInput::make('initial_quantity')
                    ->numeric(),

                TextInput::make('quantity_in')
                    ->numeric(),

                TextInput::make('quantity_out')
                    ->numeric(),

                TextInput::make('unit_price')
                    ->numeric(),

                DatePicker::make('expiry_date'),
                DatePicker::make('delivery_date'),
                ToggleButtons::make('is_in_stock')
                    ->label('En stock')
                    ->inline(false)
                    ->boolean()
                    ->grouped(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('supplierListing.name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('supplierListing.ingredient.name')
                    ->label('Ingrédient')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('order_ref')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('batch_number')
                    ->searchable(),

                TextColumn::make('initial_quantity')
                    ->label('Stock initial')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('quantity_in')
                    ->label('Stock IN')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('quantity_out')
                    ->label('Stock OUT')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_price')
                    ->label('Prix Unitaire')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('expiry_date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('delivery_date')
                    ->date()
                    ->sortable(),

                IconColumn::make('is_in_stock')
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
                    ViewAction::make(),
                    EditAction::make(),
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
            'index' => ListSupplies::route('/'),
            'create' => CreateSupply::route('/create'),
            'view' => ViewSupply::route('/{record}'),
            'edit' => EditSupply::route('/{record}/edit'),
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
