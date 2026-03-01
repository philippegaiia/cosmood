<?php

namespace App\Filament\Resources\Supply\StockMovements\Tables;

use App\Models\Supply\SuppliesMovement;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
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

                TextColumn::make('supply.supplierListing.ingredient.base_unit')
                    ->label('Unité'),

                TextColumn::make('production.batch_number')
                    ->label('Production')
                    ->placeholder('-')
                    ->url(fn (SuppliesMovement $record): ?string => $record->production_id
                        ? route('filament.admin.resources.productions.view', ['record' => $record->production_id])
                        : null)
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Utilisateur')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Raison')
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

                SelectFilter::make('movement_type')
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

                SelectFilter::make('production')
                    ->label('Production')
                    ->relationship('production', 'batch_number')
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([
                Action::make('create_adjustment')
                    ->label('Nouvel ajustement')
                    ->icon(Heroicon::Plus)
                    ->color('gray')
                    ->form([
                        Select::make('supply_id')
                            ->label('Lot de stock')
                            ->relationship('supply', 'batch_number', fn ($query) => $query->where('is_in_stock', true))
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->batch_number} - {$record->supplierListing?->ingredient?->name}")
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make('quantity')
                            ->label('Quantité')
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
                    ->action(function (array $data): void {
                        SuppliesMovement::create([
                            'supply_id' => $data['supply_id'],
                            'quantity' => $data['quantity'],
                            'movement_type' => 'adjustment',
                            'moved_at' => $data['moved_at'],
                            'reason' => $data['reason'],
                            'user_id' => auth()->id(),
                        ]);
                    })
                    ->successNotificationTitle('Ajustement créé'),
            ])
            ->defaultSort('moved_at', 'desc');
    }
}
