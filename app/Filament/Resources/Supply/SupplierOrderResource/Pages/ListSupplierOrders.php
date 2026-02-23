<?php

namespace App\Filament\Resources\Supply\SupplierOrderResource\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Supply\SupplierOrderResource;
use App\Models\Supply\SupplierOrder;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSupplierOrders extends ListRecords
{
    protected static string $resource = SupplierOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make()
                ->badge(SupplierOrder::all()->count()),

            'draft' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_status', OrderStatus::Draft->value))
                ->badge(SupplierOrder::query()->where('order_status', OrderStatus::Draft->value)->count()),

            'passed' => Tab::make('Passées')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_status', OrderStatus::Passed->value))
                ->badge(SupplierOrder::query()->where('order_status', OrderStatus::Passed->value)->count()),

            'confirmed' => Tab::make('Confirmée')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_status', OrderStatus::Confirmed->value))
                ->badge(SupplierOrder::query()->where('order_status', OrderStatus::Confirmed->value)->count()),

            'delivered' => Tab::make('Livrées')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_status', OrderStatus::Delivered->value))
                ->badge(SupplierOrder::query()->where('order_status', OrderStatus::Delivered->value)->count()),

        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'confirmed';
    }
}
