<?php

namespace App\Filament\Resources\TaskTemplates\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TaskTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Nom'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productType.name')
                    ->label(__('Type produit'))
                    ->badge()
                    ->placeholder(__('Global')),
                TextColumn::make('items_count')
                    ->label(__('Nombre de tâches'))
                    ->counts('items')
                    ->badge(),
                IconColumn::make('is_default')
                    ->label(__('Par défaut'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('Créé le'))
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ->defaultSort('name');
    }
}
