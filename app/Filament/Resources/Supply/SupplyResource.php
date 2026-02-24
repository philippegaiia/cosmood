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
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group as TableGroup;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'supplierListing.ingredient',
                'supplierListing.supplier',
                'sourceProduction.product',
            ]))
            ->columns([
                TextColumn::make('supplierListing.ingredient.name')
                    ->label('Ingrédient')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('batch_number')
                    ->label('Lot')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('source')
                    ->label('Source')
                    ->state(fn (Supply $record): string => $record->source_production_id !== null ? 'Interne' : 'Achat')
                    ->badge()
                    ->color(fn (Supply $record): string => $record->source_production_id !== null ? 'info' : 'gray')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('CASE WHEN source_production_id IS NULL THEN 0 ELSE 1 END '.$direction)),

                TextColumn::make('source_reference')
                    ->label('Réf source')
                    ->state(fn (Supply $record): string => $record->source_production_id !== null
                        ? ($record->sourceProduction?->getLotDisplayLabel() ?? '-')
                        : ($record->order_ref ?? '-'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('quantity_in')
                    ->label('Qté reçue (kg)')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')),

                TextColumn::make('quantity_out')
                    ->label('Qté sortie (kg)')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->summarize(Sum::make()),

                TextColumn::make('allocated_quantity')
                    ->label('Réservée (kg)')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->summarize(Sum::make()),

                TextColumn::make('available_quantity')
                    ->label('Disponible (kg)')
                    ->state(fn (Supply $record): float => round($record->getAvailableQuantity(), 3))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0) - COALESCE(allocated_quantity, 0)) '.$direction))
                    ->color(fn (float $state): string => $state <= 0 ? 'danger' : ($state < 5 ? 'warning' : 'success')),

                TextColumn::make('unit_price')
                    ->label('Prix (EUR/kg)')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('delivery_date')
                    ->label('Entrée')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('expiry_date')
                    ->label('DLUO')
                    ->date()
                    ->sortable()
                    ->color(fn (Supply $record): ?string => $record->expiry_date === null
                        ? null
                        : ($record->expiry_date->isPast() ? 'danger' : ($record->expiry_date->lte(now()->addDays(45)) ? 'warning' : 'success'))),

                TextColumn::make('supplierListing.supplier.name')
                    ->label('Fournisseur')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('supplierListing.name')
                    ->label('Réf fournisseur')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_in_stock')
                    ->label('En stock')
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
            ->groups([
                TableGroup::make('supplierListing.ingredient.name')
                    ->label('Par ingrédient')
                    ->collapsible(),
            ])
            ->filters([
                SelectFilter::make('ingredient')
                    ->label('Ingrédient')
                    ->relationship('supplierListing.ingredient', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        'purchase' => 'Achat',
                        'internal' => 'Interne',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'purchase' => $query->whereNull('source_production_id'),
                            'internal' => $query->whereNotNull('source_production_id'),
                            default => $query,
                        };
                    }),
                TernaryFilter::make('is_in_stock')
                    ->label('En stock'),
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
