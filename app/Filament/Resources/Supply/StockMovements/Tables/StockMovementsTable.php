<?php

namespace App\Filament\Resources\Supply\StockMovements\Tables;

use App\Models\Supply\SuppliesMovement;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Stock Movements table configuration.
 *
 * Shows supply movement history:
 * - Entry (reception)
 * - Allocation (reservation)
 * - Consumption (usage)
 *
 * Default filter: last 3 months
 * Read-only view for audit and control.
 */
class StockMovementsTable
{
    /**
     * Configure the stock movements table.
     *
     * @param  Table  $table  The table instance to configure
     * @return Table The configured table
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'supply.supplierListing.ingredient',
                    'supply.supplierListing.supplier',
                    'production',
                    'user',
                ]))
            ->columns([
                TextColumn::make('moved_at')
                    ->label(__('Date'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('supply.supplierListing.ingredient.name')
                    ->label(__('Ingrédient'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supply.batch_number')
                    ->label(__('Lot'))
                    ->placeholder(__('-'))
                    ->sortable(),

                TextColumn::make('movement_type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (SuppliesMovement $record): string => match ($record->movement_type) {
                        'entry', 'reception' => 'Entrée',
                        'allocation' => $record->quantity >= 0 ? 'Réservation (+)' : 'Réservation (-)',
                        'consumption', 'use' => 'Consommation',
                        'adjustment', 'correction' => 'Ajustement',
                        default => $record->movement_type,
                    })
                    ->color(fn (SuppliesMovement $record): string => match ($record->movement_type) {
                        'entry', 'reception' => 'success',
                        'allocation' => $record->quantity >= 0 ? 'warning' : 'info',
                        'consumption', 'use' => 'info',
                        'adjustment', 'correction' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('quantity')
                    ->label(__('Quantité'))
                    ->numeric(decimalPlaces: 3)
                    ->color(fn (SuppliesMovement $record): string => $record->quantity < 0 ? 'danger' : 'success')
                    ->sortable(),

                TextColumn::make('supply.supplierListing.ingredient.base_unit')
                    ->label(__('Unité')),

                TextColumn::make('production.batch_number')
                    ->label(__('production.label'))
                    ->placeholder(__('-'))
                    ->url(fn (SuppliesMovement $record): ?string => $record->production_id
                        ? route('filament.admin.resources.production.productions.view', ['record' => $record->production_id])
                        : null)
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label(__('Utilisateur'))
                    ->placeholder(__('-'))
                    ->sortable(),

                TextColumn::make('reason')
                    ->label(__('Raison'))
                    ->placeholder(__('-'))
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('last_3_months')
                    ->label(__('3 derniers mois'))
                    ->query(fn (Builder $query): Builder => $query->where('moved_at', '>=', Carbon::now()->subMonths(3)))
                    ->default(),

                Filter::make('date_range')
                    ->label(__('Période personnalisée'))
                    ->schema([
                        DatePicker::make('from')->label(__('Du')),
                        DatePicker::make('to')->label(__('Au')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q) => $q->where('moved_at', '>=', $data['from']))
                            ->when($data['to'], fn ($q) => $q->where('moved_at', '<=', $data['to']));
                    }),

                SelectFilter::make('movement_type')
                    ->label(__('Type'))
                    ->options([
                        'entry' => 'Entrée',
                        'allocation' => 'Allocation',
                        'consumption' => 'Consommation',
                        'adjustment' => 'Ajustement',
                    ]),

                SelectFilter::make('ingredient')
                    ->label(__('Ingrédient'))
                    ->relationship('supply.supplierListing.ingredient', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('production')
                    ->label(__('production.label'))
                    ->relationship('production', 'batch_number')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('moved_at', 'desc');
    }
}
