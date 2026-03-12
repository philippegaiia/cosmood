<?php

namespace App\Filament\Resources\Supply\SupplyResource\Tables;

use App\Models\Supply\SuppliesMovement;
use App\Models\Supply\Supply;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Supplies table configuration.
 *
 * This class encapsulates all table-related configuration for the Supply resource,
 * following Filament v5 best practices of extracting table definitions from resources.
 */
class SuppliesTable
{
    /**
     * Configure the supplies table.
     *
     * @param  Table  $table  The table instance to configure
     * @return Table The configured table
     */
    public static function configure(Table $table): Table
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
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->icon(fn (Supply $record): ?Heroicon => self::getIngredientAlertIcon($record)),

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

                ViewColumn::make('stock_availability')
                    ->label('Stock disponible')
                    ->view('components.stock-meter')
                    ->getStateUsing(function (Supply $record): array {
                        $available = $record->getAvailableQuantity();
                        $total = $record->getTotalQuantity();
                        $allocated = $record->allocated_quantity ?? 0;
                        $ingredient = $record->supplierListing?->ingredient;
                        $minStock = $ingredient?->stock_min ?? null;
                        $isBelowMin = $minStock !== null && $minStock > 0 && $available < $minStock;

                        return [
                            'available' => $available,
                            'allocated' => $allocated,
                            'total' => $total,
                            'unit' => $record->getUnitOfMeasure(),
                            'min_stock' => $minStock,
                            'is_below_min' => $isBelowMin,
                        ];
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0) - COALESCE(allocated_quantity, 0)) '.$direction)),

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

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_used_at')
                    ->label('Dernière utilisation')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->placeholder('Jamais utilisé')
                    ->toggleable(),
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
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),

                    Action::make('adjust')
                        ->label('Ajuster')
                        ->icon(Heroicon::AdjustmentsHorizontal)
                        ->color('warning')
                        ->schema([
                            TextInput::make('adjustment_quantity')
                                ->label('Quantité d\'ajustement')
                                ->numeric()
                                ->step(0.001)
                                ->required()
                                ->helperText('Positive = ajout de stock, Négative = retrait de stock'),

                            DateTimePicker::make('moved_at')
                                ->label('Date et heure')
                                ->default(now())
                                ->required(),

                            Textarea::make('reason')
                                ->label('Raison de l\'ajustement')
                                ->required()
                                ->placeholder('Ex: Inventaire, correction erreur, etc.'),
                        ])
                        ->action(function (array $data, Supply $record): void {
                            SuppliesMovement::create([
                                'supply_id' => $record->id,
                                'quantity' => $data['adjustment_quantity'],
                                'movement_type' => 'adjustment',
                                'moved_at' => $data['moved_at'],
                                'reason' => $data['reason'],
                                'user_id' => auth()->id(),
                            ]);

                            // Update supply quantities based on adjustment
                            if ($data['adjustment_quantity'] > 0) {
                                $record->quantity_in = ($record->quantity_in ?? 0) + $data['adjustment_quantity'];
                            } else {
                                $record->quantity_out = ($record->quantity_out ?? 0) + abs($data['adjustment_quantity']);
                            }
                            $record->save();
                        })
                        ->successNotificationTitle('Ajustement créé'),

                    Action::make('markOutOfStock')
                        ->label('Marquer épuisé')
                        ->icon(Heroicon::ArchiveBoxXMark)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Marquer ce lot comme épuisé?')
                        ->modalDescription('Cette action marquera le lot comme hors stock. Assurez-vous d\'avoir effectué un ajustement manuel si nécessaire.')
                        ->visible(fn (Supply $record): bool => $record->is_in_stock)
                        ->action(function (Supply $record): void {
                            $record->update(['is_in_stock' => false]);

                            Notification::make()
                                ->title('Lot marqué comme épuisé')
                                ->body("Le lot {$record->batch_number} a été marqué comme hors stock.")
                                ->success()
                                ->send();
                        })
                        ->successNotificationTitle('Lot marqué comme épuisé'),
                ]),
            ])
            ->groups([
                Group::make('supplierListing.ingredient.name')
                    ->label('Ingrédient')
                    ->collapsible(),
                Group::make('supplierListing.supplier.name')
                    ->label('Fournisseur')
                    ->collapsible(),
                Group::make('source')
                    ->label('Source')
                    ->getTitleFromRecordUsing(fn (Supply $record): string => $record->source_production_id !== null ? 'Interne' : 'Achat')
                    ->collapsible(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markOutOfStock')
                        ->label('Marquer épuisés')
                        ->icon(Heroicon::ArchiveBoxXMark)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Marquer les lots comme épuisés?')
                        ->modalDescription('Cette action marquera tous les lots sélectionnés comme hors stock.')
                        ->action(function (Collection $records): void {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->is_in_stock) {
                                    $record->update(['is_in_stock' => false]);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->title('Lots marqués comme épuisés')
                                ->body("{$count} lot(s) ont été marqués comme hors stock.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Get alert icon if ingredient's consolidated stock is below minimum.
     */
    private static function getIngredientAlertIcon(Supply $record): ?Heroicon
    {
        $ingredient = $record->supplierListing?->ingredient;

        if (! $ingredient || ! $ingredient->stock_min || $ingredient->stock_min <= 0) {
            return null;
        }

        $consolidatedAvailable = $ingredient->getTotalAvailableStock();

        if ($consolidatedAvailable < $ingredient->stock_min) {
            return Heroicon::ExclamationTriangle;
        }

        return null;
    }

    public static function getEloquentQuery(Builder $query): Builder
    {
        return $query;
    }
}
