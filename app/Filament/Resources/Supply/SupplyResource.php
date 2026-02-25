<?php

namespace App\Filament\Resources\Supply;

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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
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

    public static function canCreate(): bool
    {
        return false;
    }

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
                            ->relationship('supplierListing', 'name')
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
                        'xl' => 4,
                    ])
                    ->schema([
                        TextInput::make('initial_quantity')
                            ->label('Qté initiale')
                            ->numeric(),
                        TextInput::make('quantity_in')
                            ->label('Qté reçue')
                            ->numeric(),
                        TextInput::make('quantity_out')
                            ->label('Qté consommée')
                            ->numeric(),
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
                    ->label('Qté reçue')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('quantity_out')
                    ->label('Qté consommée')
                    ->description('Sortie réelle (prod terminées)')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('physical_stock')
                    ->label('Stock physique')
                    ->state(fn (Supply $record): float => round($record->getTotalQuantity(), 3))
                    ->description('Reçue - consommée')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0)) '.$direction)),

                TextColumn::make('allocated_quantity')
                    ->label('Qté allouée')
                    ->description('Réservée non consommée')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('available_quantity')
                    ->label('Disponible à allouer')
                    ->state(fn (Supply $record): float => round($record->getAvailableQuantity(), 3))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0) - COALESCE(allocated_quantity, 0)) '.$direction))
                    ->description('Stock physique - allouée')
                    ->color(function (Supply $record): string {
                        $available = $record->getAvailableQuantity();

                        if ($available <= 0) {
                            return 'danger';
                        }

                        $ingredient = $record->supplierListing?->ingredient;

                        if (! $ingredient) {
                            return 'success';
                        }

                        $ingredientId = (int) $ingredient->id;

                        static $ingredientTotals = [];

                        if (! array_key_exists($ingredientId, $ingredientTotals)) {
                            $ingredientTotals[$ingredientId] = $ingredient->getTotalAvailableStock();
                        }

                        $stockMin = (float) ($ingredient->stock_min ?? 0);

                        if ($stockMin > 0 && (float) $ingredientTotals[$ingredientId] <= $stockMin) {
                            return 'danger';
                        }

                        return 'success';
                    }),

                TextColumn::make('supplierListing.unit_of_measure')
                    ->label('Unité')
                    ->state(fn (Supply $record): string => $record->getUnitOfMeasure())
                    ->sortable(),

                TextColumn::make('unit_price')
                    ->label('Prix unitaire')
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
