<?php

namespace App\Filament\Resources\Supply;

use App\Filament\Resources\Supply\SupplyResource\Pages\EditSupply;
use App\Filament\Resources\Supply\SupplyResource\Pages\ListSupplies;
use App\Filament\Resources\Supply\SupplyResource\Pages\ViewSupply;
use App\Filament\Resources\Supply\SupplyResource\RelationManagers;
use App\Filament\Resources\Supply\SupplyResource\Tables\SuppliesTable;
use App\Models\Supply\Supply;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Supply Resource.
 *
 * Manages inventory/supply records with:
 * - Visual stock meters with consolidated ingredient-level alerts
 * - Tab-based filtering (all, in stock, alerts)
 * - Delegated table configuration via SuppliesTable
 */
class SupplyResource extends Resource
{
    protected static ?string $model = Supply::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Achats';

    protected static ?string $navigationLabel = 'Inventaire';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-c-book-open';

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Configure the supply form schema.
     *
     * @param  Schema  $schema  The schema instance to configure
     * @return Schema The configured schema
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Lot et source')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'md' => 3,
                    ])
                    ->schema([
                        Select::make('supplier_listing_id')
                            ->label('Ingrédient')
                            ->relationship(
                                name: 'supplierListing',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->with('supplier'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Model $record) => trim("{$record->name} ({$record->unit_weight} {$record->unit_of_measure}) - {$record->supplier->name}"))
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('order_ref')
                            ->label('Réf commande')
                            ->maxLength(255),
                        TextInput::make('batch_number')
                            ->label('N° lot')
                            ->maxLength(255),
                    ]),

                Section::make('Quantités')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 5,
                    ])
                    ->schema([
                        TextInput::make('initial_quantity')
                            ->label('Qté initiale')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('Valeur en lecture seule. Utiliser l\'action "Ajuster" depuis la liste inventaire.')),
                        TextInput::make('quantity_in')
                            ->label('Qté reçue')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('Valeur en lecture seule. Utiliser l\'action "Ajuster" depuis la liste inventaire.')),
                        TextInput::make('quantity_out')
                            ->label('Qté consommée')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('Valeur en lecture seule. Utiliser l\'action "Ajuster" depuis la liste inventaire.')),
                        TextInput::make('allocated_quantity_display')
                            ->label('Qté réservée')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (TextInput $component, $state, $record) {
                                if ($record) {
                                    $component->state($record->getAllocatedQuantity());
                                }
                            })
                            ->helperText(__('Quantité réservée pour les productions en cours (calculée automatiquement)')),
                        ToggleButtons::make('is_in_stock')
                            ->label('En stock')
                            ->inline(false)
                            ->boolean()
                            ->grouped(),
                    ]),

                Section::make('Prix et dates')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextInput::make('unit_price')
                            ->label('Prix unitaire')
                            ->numeric(),
                        DatePicker::make('delivery_date')
                            ->label('Date d\'entrée'),
                        DatePicker::make('expiry_date')
                            ->label('DLUO'),
                    ]),
            ]);
    }

    /**
     * Configure the supplies table.
     *
     * Delegates to SuppliesTable for all table configuration.
     *
     * @param  Table  $table  The table instance to configure
     * @return Table The configured table
     */
    public static function table(Table $table): Table
    {
        return SuppliesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\StockMovementsRelationManager::make(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplies::route('/'),
            'view' => ViewSupply::route('/{record}'),
            'edit' => EditSupply::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->canManageSupplyInventory() ?? false;
    }
}
