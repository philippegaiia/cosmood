<?php

namespace App\Filament\Resources\Supply\SupplyResource\Tables;

use App\Models\Supply\Ingredient;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quick view table showing consolidated stock per ingredient.
 *
 * This table groups supplies by ingredient and shows:
 * - Consolidated available stock across all lots
 * - Stock min alerts
 * - Number of supply lots
 * - Quick actions to view details
 */
class SupplyQuickViewTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select('supplier_listings.ingredient_id')
                ->selectRaw('COUNT(DISTINCT supplies.id) as lots_count')
                ->selectRaw('COALESCE(SUM(COALESCE(supplies.quantity_in, supplies.initial_quantity, 0) - COALESCE(supplies.quantity_out, 0) - COALESCE(supplies.allocated_quantity, 0)), 0) as consolidated_available')
                ->selectRaw('COALESCE(SUM(COALESCE(supplies.quantity_in, supplies.initial_quantity, 0) - COALESCE(supplies.quantity_out, 0)), 0) as consolidated_total')
                ->selectRaw('COALESCE(SUM(supplies.allocated_quantity), 0) as consolidated_allocated')
                ->join('supplier_listings', 'supplies.supplier_listing_id', '=', 'supplier_listings.id')
                ->join('ingredients', 'supplier_listings.ingredient_id', '=', 'ingredients.id')
                ->groupBy('supplier_listings.ingredient_id')
                ->with(['supplierListing.ingredient'])
            )
            ->columns([
                TextColumn::make('ingredient_name')
                    ->label('Ingrédient')
                    ->state(fn ($record): string => $record->supplierListing?->ingredient?->name ?? '-')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('supplierListing.ingredient', fn ($q) => $q->where('name', 'like', "%{$search}%")))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('(SELECT name FROM ingredients WHERE id = supplier_listings.ingredient_id) '.$direction))
                    ->icon(fn ($record): ?Heroicon => self::getAlertIcon($record)),

                ViewColumn::make('consolidated_stock')
                    ->label('Stock consolidé')
                    ->view('components.stock-meter')
                    ->getStateUsing(function ($record): array {
                        $ingredient = $record->supplierListing?->ingredient;
                        $available = (float) ($record->consolidated_available ?? 0);
                        $total = (float) ($record->consolidated_total ?? 0);
                        $allocated = (float) ($record->consolidated_allocated ?? 0);
                        $minStock = $ingredient?->stock_min ?? null;
                        $isBelowMin = $minStock !== null && $minStock > 0 && $available < $minStock;

                        return [
                            'available' => $available,
                            'allocated' => $allocated,
                            'total' => $total,
                            'unit' => $ingredient?->base_unit?->value ?? 'kg',
                            'min_stock' => $minStock,
                            'is_below_min' => $isBelowMin,
                        ];
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('COALESCE(SUM(COALESCE(supplies.quantity_in, supplies.initial_quantity, 0) - COALESCE(supplies.quantity_out, 0) - COALESCE(supplies.allocated_quantity, 0)), 0) '.$direction)),

                TextColumn::make('lots_count')
                    ->label('Lots')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('stock_min')
                    ->label('Stock min')
                    ->state(fn ($record): ?float => $record->supplierListing?->ingredient?->stock_min)
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('stock_alert')
                    ->label('Alerte stock')
                    ->placeholder('Tous')
                    ->trueLabel('Stock faible')
                    ->falseLabel('Stock OK')
                    ->queries(
                        true: fn (Builder $query) => $query
                            ->havingRaw('COALESCE(SUM(COALESCE(supplies.quantity_in, supplies.initial_quantity, 0) - COALESCE(supplies.quantity_out, 0) - COALESCE(supplies.allocated_quantity, 0)), 0) < (SELECT stock_min FROM ingredients WHERE id = supplier_listings.ingredient_id)'),
                        false: fn (Builder $query) => $query
                            ->havingRaw('COALESCE(SUM(COALESCE(supplies.quantity_in, supplies.initial_quantity, 0) - COALESCE(supplies.quantity_out, 0) - COALESCE(supplies.allocated_quantity, 0)), 0) >= (SELECT stock_min FROM ingredients WHERE id = supplier_listings.ingredient_id)'),
                    ),
            ])
            ->defaultSort('consolidated_available', 'asc');
    }

    /**
     * Get alert icon if stock is below minimum.
     */
    private static function getAlertIcon($record): ?Heroicon
    {
        $ingredient = $record->supplierListing?->ingredient;
        $available = (float) ($record->consolidated_available ?? 0);
        $minStock = $ingredient?->stock_min ?? null;

        if ($minStock !== null && $minStock > 0 && $available < $minStock) {
            return Heroicon::ExclamationTriangle;
        }

        return null;
    }
}
