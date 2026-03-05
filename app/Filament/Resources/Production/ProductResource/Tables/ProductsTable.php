<?php

namespace App\Filament\Resources\Production\ProductResource\Tables;

use App\Filament\Resources\Production\ProductResource\ProductResource;
use App\Models\Production\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productCategory.name')
                    ->label(__('Catégorie'))
                    ->sortable(),
                TextColumn::make('productType.name')
                    ->label(__('Type'))
                    ->sortable()
                    ->placeholder(__('-')),
                TextColumn::make('formula_name')
                    ->label(__('Formule par défaut'))
                    ->state(fn (Product $record): ?string => $record->defaultFormula()?->name)
                    ->placeholder(__('-')),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('producedIngredient.name')
                    ->label(__('Ingrédient fabriqué'))
                    ->placeholder(__('-'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('wp_code')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('launch_date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('net_weight')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('ean_code')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_active')
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                ReplicateAction::make()
                    ->label(__('Dupliquer'))
                    ->excludeAttributes([
                        'id',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ])
                    ->requiresConfirmation()
                    ->mutateRecordDataUsing(function (array $data): array {
                        $data['name'] = ($data['name'] ?? __('Produit')).' (copy)';

                        return $data;
                    })
                    ->successRedirectUrl(fn (Model $replica): string => ProductResource::getUrl('edit', ['record' => $replica])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
