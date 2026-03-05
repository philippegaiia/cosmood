<?php

namespace App\Filament\Resources\Production\ProductResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class ProductionsRelationManager extends RelationManager
{
    protected static string $relationship = 'productions';

    protected static ?string $title = 'Productions';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('batch_number')
                    ->label('N° de lot')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('production_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->getLabel() ?? '-')
                    ->color(fn ($state): string|array|null => $state?->getColor() ?? 'gray')
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('planned_quantity')
                    ->label('Quantité planifiée')
                    ->numeric(decimalPlaces: 1)
                    ->suffix(' kg'),
                \Filament\Tables\Columns\TextColumn::make('expected_units')
                    ->label('Unités attendues')
                    ->numeric(),
            ])
            ->defaultSort('production_date', 'desc');
    }
}
