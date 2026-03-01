<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Supply\SupplyResource;
use App\Models\Supply\Ingredient;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
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
                        return Ingredient::query()->whereIn('id', $collection->pluck('id'));
                    })
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Ingrédient')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('available_stock')
                    ->label('Disponible')
                    ->state(fn (Ingredient $record): float => $record->getTotalAvailableStock())
                    ->numeric(decimalPlaces: 2)
                    ->color('danger')
                    ->sortable(),

                TextColumn::make('stock_min')
                    ->label('Minimum')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('shortage')
                    ->label('Manque')
                    ->state(fn (Ingredient $record): float => $record->stock_min - $record->getTotalAvailableStock())
                    ->numeric(decimalPlaces: 2)
                    ->color('rose')
                    ->sortable(),

                TextColumn::make('base_unit')
                    ->label('Unité'),
            ])
            ->actions([
                Action::make('view_supplies')
                    ->label('Voir lots')
                    ->icon(Heroicon::Eye)
                    ->color('gray')
                    ->url(fn (Ingredient $record): string => SupplyResource::getUrl('index', [
                        'tableFilters[ingredient][value]' => $record->id,
                    ])),
            ])
            ->emptyStateHeading('Aucune alerte stock')
            ->emptyStateDescription('Tous les ingrédients ont un stock suffisant.')
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return true;
    }
}
