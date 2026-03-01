<?php

namespace App\Filament\Resources\Supply\SupplyResource\Tables;

use App\Models\Supply\Supply;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Detail table showing individual supply lots.
 *
 * This table shows detailed information for each supply lot:
 * - Individual lot quantities
 * - Allocation details
 * - Pricing and dates
 * - Can be filtered by ingredient
 */
class SupplyDetailTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'supplierListing.ingredient',
                'supplierListing.supplier',
                'sourceProduction.product',
            ]))
            ->columns([
                TextColumn::make('supplierListing.ingredient.name')
                    ->label('Ingrédient')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('batch_number')
                    ->label('Lot')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('source')
                    ->label('Source')
                    ->state(fn (Supply $record): string => $record->source_production_id !== null ? 'Interne' : 'Achat')
                    ->badge()
                    ->color(fn (Supply $record): string => $record->source_production_id !== null ? 'info' : 'gray')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('CASE WHEN source_production_id IS NULL THEN 0 ELSE 1 END '.$direction)),

                TextColumn::make('source_reference')
                    ->label('Réf source')
                    ->state(fn (Supply $record): string => $record->source_production_id !== null
                        ? ($record->sourceProduction?->getLotDisplayLabel() ?? '-')
                        : ($record->order_ref ?? '-')),

                TextColumn::make('quantity_in')
                    ->label('Qté reçue')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('quantity_out')
                    ->label('Qté consommée')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('physical_stock')
                    ->label('Stock physique')
                    ->state(fn (Supply $record): float => round($record->getTotalQuantity(), 3))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0)) '.$direction)),

                TextColumn::make('allocated_quantity')
                    ->label('Qté allouée')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                ViewColumn::make('stock_availability')
                    ->label('Disponible')
                    ->view('components.stock-meter')
                    ->getStateUsing(function (Supply $record): array {
                        $available = $record->getAvailableQuantity();
                        $total = $record->getTotalQuantity();
                        $allocated = $record->allocated_quantity ?? 0;
                        $ingredient = $record->supplierListing?->ingredient;
                        $minStock = $ingredient?->stock_min ?? null;
                        $isBelowMin = $minStock !== null && $minStock > 0 && $available < $minStock;

                        return [
                            'available' => $available,
                            'allocated' => $allocated,
                            'total' => $total,
                            'unit' => $record->getUnitOfMeasure(),
                            'min_stock' => $minStock,
                            'is_below_min' => $isBelowMin,
                        ];
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0) - COALESCE(allocated_quantity, 0)) '.$direction)),

                TextColumn::make('supplierListing.unit_of_measure')
                    ->label('Unité')
                    ->state(fn (Supply $record): string => $record->getUnitOfMeasure())
                    ->sortable(),

                TextColumn::make('unit_price')
                    ->label('Prix unitaire')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('delivery_date')
                    ->label('Entrée')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('expiry_date')
                    ->label('DLUO')
                    ->date()
                    ->sortable()
                    ->color(fn (Supply $record): ?string => $record->expiry_date === null
                        ? null
                        : ($record->expiry_date->isPast() ? 'danger' : ($record->expiry_date->lte(now()->addDays(45)) ? 'warning' : 'success'))),

                TextColumn::make('supplierListing.supplier.name')
                    ->label('Fournisseur')
                    ->searchable(),

                TextColumn::make('supplierListing.name')
                    ->label('Réf fournisseur')
                    ->searchable(),

                IconColumn::make('is_in_stock')
                    ->label('En stock')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('ingredient')
                    ->label('Ingrédient')
                    ->relationship('supplierListing.ingredient', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        'purchase' => 'Achat',
                        'internal' => 'Interne',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'purchase' => $query->whereNull('source_production_id'),
                            'internal' => $query->whereNotNull('source_production_id'),
                            default => $query,
                        };
                    }),
                TernaryFilter::make('is_in_stock')
                    ->label('En stock'),
                TrashedFilter::make(),
            ])
            ->defaultSort('delivery_date', 'desc');
    }
}
