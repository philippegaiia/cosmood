<?php

namespace App\Filament\Resources\Production\ProductionWaves\RelationManagers;

use App\Filament\Resources\Production\ProductionResource;
use App\Models\Production\Production;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductionsRelationManager extends RelationManager
{
    protected static string $relationship = 'productions';

    protected static ?string $title = 'Productions liées';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('batch_number')
                    ->label('Lot')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Produit')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('planned_quantity')
                    ->label('Qté planifiée (kg)')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                TextColumn::make('production_date')
                    ->label('Date production')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('ready_date')
                    ->label('Date prêt')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->recordUrl(fn (Production $record): string => ProductionResource::getUrl('edit', ['record' => $record]))
            ->openRecordUrlInNewTab()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('product'))
            ->defaultSort('production_date', 'asc');
    }
}
