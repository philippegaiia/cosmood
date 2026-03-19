<?php

namespace App\Filament\Resources\Production\ProductTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('resources.product_types.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productCategory.name')
                    ->label(__('resources.product_types.table.category'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('qcTemplate.name')
                    ->label(__('resources.product_types.table.qc_template'))
                    ->badge()
                    ->placeholder(__('-'))
                    ->sortable(),
                TextColumn::make('defaultProductionLine.name')
                    ->label(__('resources.product_types.table.default_line'))
                    ->badge()
                    ->placeholder(__('-'))
                    ->sortable(),
                TextColumn::make('sizing_mode')
                    ->label(__('resources.product_types.table.sizing_mode'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('default_batch_size')
                    ->label(__('resources.product_types.table.default_batch'))
                    ->numeric()
                    ->sortable()
                    ->suffix(' kg'),
                TextColumn::make('expected_units_output')
                    ->label(__('resources.product_types.table.expected_units'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('batchSizePresets_count')
                    ->label(__('resources.product_types.table.presets'))
                    ->counts('batchSizePresets')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label(__('resources.product_types.table.is_active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['productCategory', 'qcTemplate', 'defaultProductionLine']))
            ->defaultSort('name');
    }
}
