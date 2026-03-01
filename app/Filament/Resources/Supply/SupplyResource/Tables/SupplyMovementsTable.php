<?php

namespace App\Filament\Resources\Supply\SupplyResource\Tables;

use App\Models\Supply\SuppliesMovement;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Movements table showing supply history.
 *
 * This table shows all stock movements:
 * - Entries (receptions)
 * - Allocations (reservations)
 * - Consumptions (production usage)
 * - Default filter: last 3 months
 */
class SupplyMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'supply.supplierListing.ingredient',
                'supply.supplierListing.supplier',
                'production',
                'user',
            ]))
            ->columns([
                TextColumn::make('moved_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('supply.supplierListing.ingredient.name')
                    ->label('Ingrédient')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supply.batch_number')
                    ->label('Lot')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('movement_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (SuppliesMovement $record): string => match ($record->movement_type) {
                        'entry', 'reception' => 'success',
                        'allocation', 'reserve' => 'warning',
                        'consumption', 'use' => 'info',
                        'adjustment', 'correction' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('quantity')
                    ->label('Quantité')
                    ->numeric(decimalPlaces: 3)
                    ->color(fn (SuppliesMovement $record): string => $record->quantity < 0 ? 'danger' : 'success')
                    ->sortable(),

                TextColumn::make('unit')
                    ->label('Unité')
                    ->state(fn (SuppliesMovement $record): string => $record->supply?->getUnitOfMeasure() ?? 'kg'),

                TextColumn::make('production.batch_number')
                    ->label('Production')
                    ->placeholder('-')
                    ->url(fn (SuppliesMovement $record): ?string => $record->production_id
                        ? route('filament.admin.resources.productions.view', $record->production_id)
                        : null)
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Utilisateur')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('notes')
                    ->label('Notes')
                    ->placeholder('-')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('last_3_months')
                    ->label('3 derniers mois')
                    ->query(fn (Builder $query): Builder => $query->where('moved_at', '>=', Carbon::now()->subMonths(3)))
                    ->default(),

                Filter::make('date_range')
                    ->label('Période personnalisée')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Du'),
                        \Filament\Forms\Components\DatePicker::make('to')->label('Au'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q) => $q->where('moved_at', '>=', $data['from']))
                            ->when($data['to'], fn ($q) => $q->where('moved_at', '<=', $data['to']));
                    }),

                SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'entry' => 'Entrée',
                        'allocation' => 'Allocation',
                        'consumption' => 'Consommation',
                        'adjustment' => 'Ajustement',
                    ]),

                SelectFilter::make('ingredient')
                    ->label('Ingrédient')
                    ->relationship('supply.supplierListing.ingredient', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('moved_at', 'desc');
    }
}
