<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Supply\SupplyResource;
use App\Models\Supply\Ingredient;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Stock Alerts Widget.
 *
 * Shows ingredients with consolidated stock below minimum threshold.
 * Displays available quantity, minimum required, and difference.
 *
 * Quick action to view all supplies for the ingredient.
 */
class StockAlertsWidget extends BaseWidget
{
    protected static ?string $heading = 'Alertes stock';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 6,
        'lg' => 6,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Ingredient::query()
                    ->whereNotNull('stock_min')
                    ->where('stock_min', '>', 0)
                    ->get()
                    ->filter(function (Ingredient $ingredient): bool {
                        return $ingredient->getTotalAvailableStock() < $ingredient->stock_min;
                    })
                    ->pipe(function ($collection) {
                        return Ingredient::query()
                            ->whereIn('id', $collection->pluck('id'))
                            ->limit(6);
                    })
            )
            ->columns([
                TextColumn::make('name')
                    ->label(__('Ingrédient'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('stock_level')
                    ->label(__('Disponible / minimum'))
                    ->state(fn (Ingredient $record): string => number_format($record->getTotalAvailableStock(), 2, ',', ' ').' / '.number_format((float) $record->stock_min, 2, ',', ' '))
                    ->color('danger')
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('stock_min', $direction)),

                TextColumn::make('shortage')
                    ->label(__('Manque'))
                    ->state(fn (Ingredient $record): float => $record->stock_min - $record->getTotalAvailableStock())
                    ->numeric(decimalPlaces: 2)
                    ->color('rose')
                    ->sortable(),
            ])
            ->recordUrl(fn (Ingredient $record): string => SupplyResource::getUrl('index', [
                'filters' => [
                    'ingredient' => [
                        'value' => $record->id,
                    ],
                ],
            ]))
            ->emptyStateHeading(__('Aucune alerte stock'))
            ->emptyStateDescription(__('Tous les ingrédients ont un stock suffisant.'))
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return true;
    }
}
