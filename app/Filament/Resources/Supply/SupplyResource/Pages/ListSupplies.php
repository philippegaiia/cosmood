<?php

namespace App\Filament\Resources\Supply\SupplyResource\Pages;

use App\Filament\Resources\Supply\SupplyResource;
use App\Models\Supply\Supply;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSupplies extends ListRecords
{
    protected static string $resource = SupplyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Registre lots')
                ->badge(Supply::query()->count()),

            'in_stock' => Tab::make('En stock')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_in_stock', true))
                ->badge(Supply::query()->where('is_in_stock', true)->count()),

            'internal' => Tab::make('Interne')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('source_production_id'))
                ->badge(Supply::query()->whereNotNull('source_production_id')->count()),

            'purchase' => Tab::make('Achat')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('source_production_id'))
                ->badge(Supply::query()->whereNull('source_production_id')->count()),

            'low_stock' => Tab::make('Rupture / bas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereRaw('(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0) - COALESCE(allocated_quantity, 0)) <= 0'))
                ->badge(
                    Supply::query()
                        ->whereRaw('(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0) - COALESCE(allocated_quantity, 0)) <= 0')
                        ->count()
                ),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'in_stock';
    }
}
