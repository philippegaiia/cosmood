<?php

namespace App\Filament\Resources\Holidays\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HolidaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->label(__('Date'))
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('Nom'))
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_recurring')
                    ->label(__('Récurrent'))
                    ->boolean(),
            ])
            ->filters([
                Filter::make('is_recurring')
                    ->label(__('Jours fériés récurrents'))
                    ->query(fn (Builder $query): Builder => $query->where('is_recurring', true)),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('date', 'asc');
    }
}
