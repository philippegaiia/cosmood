<?php

namespace App\Filament\Resources\Supply\SupplyResource\Pages;

use App\Filament\Resources\Supply\SupplyResource;
use App\Models\Supply\Supply;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

class ListSupplies extends ListRecords
{
    protected static string $resource = SupplyResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tous les lots')
                ->badge(Supply::query()->count()),

            'in_stock' => Tab::make('En stock')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_in_stock', true))
                ->badge(Supply::query()->where('is_in_stock', true)->count()),

            'alert' => Tab::make('Alerte stock')
                ->modifyQueryUsing(function (Builder $query): Builder {
                    return $query->whereHas('supplierListing.ingredient', function ($q) {
                        $q->whereColumn('stock_min', '>', 0)
                            ->whereRaw('(SELECT COALESCE(SUM(COALESCE(s.quantity_in, s.initial_quantity, 0) - COALESCE(s.quantity_out, 0) - COALESCE(s.allocated_quantity, 0)), 0) 
                                     FROM supplies s 
                                     JOIN supplier_listings sl ON s.supplier_listing_id = sl.id 
                                     WHERE sl.ingredient_id = ingredients.id) < stock_min');
                    });
                })
                ->badge(function (): int {
                    return \App\Models\Supply\Ingredient::query()
                        ->where('stock_min', '>', 0)
                        ->get()
                        ->filter(fn ($i) => $i->getTotalAvailableStock() < $i->stock_min)
                        ->count();
                })
                ->color('warning'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'in_stock';
    }

    public function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('movements')
                ->label('Voir mouvements')
                ->icon('heroicon-o-arrows-right-left')
                ->url(\App\Filament\Resources\Supply\SupplyMovementResource::getUrl('index'))
                ->openUrlInNewTab(),
        ];
    }
}
