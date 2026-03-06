<?php

namespace App\Filament\Resources\Production\ProductTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productCategory.name')
                    ->label('Catégorie')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('qcTemplate.name')
                    ->label('Modèle QC')
                    ->badge()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('defaultProductionLine.name')
                    ->label('Ligne défaut')
                    ->badge()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('sizing_mode')
                    ->label('Mode de calcul')
                    ->badge()
                    ->sortable(),
                TextColumn::make('default_batch_size')
                    ->label('Batch défaut')
                    ->numeric()
                    ->sortable()
                    ->suffix(' kg'),
                TextColumn::make('expected_units_output')
                    ->label('Unités attendues')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('batchSizePresets_count')
                    ->label('Préréglages')
                    ->counts('batchSizePresets')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['productCategory', 'qcTemplate', 'defaultProductionLine']))
            ->defaultSort('name');
    }
}
