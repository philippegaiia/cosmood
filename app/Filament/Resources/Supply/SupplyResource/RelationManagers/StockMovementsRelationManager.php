<?php

namespace App\Filament\Resources\Supply\SupplyResource\RelationManagers;

use App\Models\Supply\SuppliesMovement;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Historique des mouvements';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user?->canAccessStockMovements() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['production', 'user'])
                ->orderBy('moved_at', 'desc'))
            ->columns([
                TextColumn::make('moved_at')
                    ->label(__('Date'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('movement_type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (SuppliesMovement $record): string => match ($record->movement_type) {
                        'in', 'entry', 'reception' => 'Entrée',
                        'allocation' => $record->quantity >= 0 ? 'Réservation (+)' : 'Réservation (-)',
                        'out', 'consumption', 'use' => 'Consommation',
                        'adjustment', 'correction' => 'Ajustement',
                        default => $record->movement_type,
                    })
                    ->color(fn (SuppliesMovement $record): string => match ($record->movement_type) {
                        'in', 'entry', 'reception' => 'success',
                        'allocation' => $record->quantity >= 0 ? 'warning' : 'info',
                        'out', 'consumption', 'use' => 'info',
                        'adjustment', 'correction' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('quantity')
                    ->label(__('Quantité'))
                    ->numeric(decimalPlaces: 3)
                    ->color(fn (SuppliesMovement $record): string => $record->quantity < 0 ? 'danger' : 'success')
                    ->sortable(),

                TextColumn::make('unit')
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
            ->defaultSort('moved_at', 'desc');
    }
}
