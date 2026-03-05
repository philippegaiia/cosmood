<?php

namespace App\Filament\Resources\Production\ProductResource\Pages;

use App\Filament\Resources\Production\ProductResource\ProductResource;
use App\Models\Production\Product;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    public function getTabs(): array
    {
        return [
            'active' => Tab::make(__('Produits actifs'))
                ->badge(fn (): int => Product::query()->where('is_active', true)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true)),

            'inactive' => Tab::make(__('Produits inactifs'))
                ->badge(fn (): int => Product::query()->where('is_active', false)->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
