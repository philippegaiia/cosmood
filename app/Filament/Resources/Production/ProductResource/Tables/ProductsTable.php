<?php

namespace App\Filament\Resources\Production\ProductResource\Tables;

use App\Filament\Resources\Production\ProductResource\ProductResource;
use App\Models\Production\Product;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('resources.products.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('brand.name')
                    ->label(__('resources.products.table.brand'))
                    ->sortable()
                    ->placeholder(__('-')),
                TextColumn::make('collection.name')
                    ->label(__('resources.products.table.collection'))
                    ->sortable()
                    ->placeholder(__('-'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('productCategory.name')
                    ->label(__('resources.products.table.category'))
                    ->sortable(),
                TextColumn::make('productType.name')
                    ->label(__('resources.products.table.type'))
                    ->sortable()
                    ->placeholder(__('-')),
                TextColumn::make('formula_name')
                    ->label(__('resources.products.table.default_formula'))
                    ->state(fn (Product $record): ?string => $record->defaultFormula()?->name)
                    ->placeholder(__('-')),
                TextColumn::make('code')
                    ->label(__('resources.products.table.code'))
                    ->searchable(),
                TextColumn::make('producedIngredient.name')
                    ->label(__('resources.products.table.manufactured_ingredient'))
                    ->placeholder(__('-'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('wp_code')
                    ->label(__('resources.products.table.ecommerce_sku'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('launch_date')
                    ->label(__('resources.products.table.launch_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('net_weight')
                    ->label(__('resources.products.table.net_weight'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('ean_code')
                    ->label(__('resources.products.table.ean_code'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_active')
                    ->label(__('resources.products.table.is_active'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('resources.products.table.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('resources.products.table.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('brand_id')
                    ->label(__('resources.products.table.brand'))
                    ->relationship('brand', 'name'),
                SelectFilter::make('collection_id')
                    ->label(__('resources.products.table.collection'))
                    ->relationship('collection', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                ReplicateAction::make()
                    ->label(__('resources.products.actions.duplicate'))
                    ->excludeAttributes([
                        'id',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ])
                    ->requiresConfirmation()
                    ->mutateRecordDataUsing(function (array $data): array {
                        $data['name'] = __('resources.products.table.copy_name', [
                            'name' => $data['name'] ?? __('resources.products.singular'),
                        ]);

                        return $data;
                    })
                    ->successRedirectUrl(fn (Model $replica): string => ProductResource::getUrl('edit', ['record' => $replica])),
            ]);
    }
}
