<?php

namespace App\Filament\Resources\Production\ProductResource\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductionsRelationManager extends RelationManager
{
    protected static string $relationship = 'productions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Productions');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('batch_number')
                    ->label(__('N° de lot'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('production_date')
                    ->label(__('Date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Statut'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->getLabel() ?? __('-'))
                    ->color(fn ($state): string|array|null => $state?->getColor() ?? 'gray')
                    ->sortable(),
                TextColumn::make('planned_quantity')
                    ->label(__('Quantité planifiée'))
                    ->numeric(decimalPlaces: 1)
                    ->suffix(__(' kg')),
                TextColumn::make('expected_units')
                    ->label(__('Unités attendues'))
                    ->numeric(),
                TextColumn::make('actual_units')
                    ->label(__('Qté produite (réel)'))
                    ->numeric()
                    ->placeholder(__('-')),
            ])
            ->filters([
                Filter::make('production_date_range')
                    ->label(__('Période'))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('Du')),
                        DatePicker::make('to')
                            ->label(__('Au')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                filled($data['from'] ?? null),
                                fn (Builder $query): Builder => $query->whereDate('production_date', '>=', $data['from'])
                            )
                            ->when(
                                filled($data['to'] ?? null),
                                fn (Builder $query): Builder => $query->whereDate('production_date', '<=', $data['to'])
                            );
                    }),
            ])
            ->defaultSort('production_date', 'desc');
    }
}
