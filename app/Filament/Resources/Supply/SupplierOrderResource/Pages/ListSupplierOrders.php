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
                ->badge(SupplierOrder::query()->count()),

            'draft' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_status', OrderStatus::Draft->value))
                ->badge(SupplierOrder::query()->where('order_status', OrderStatus::Draft->value)->count()),

            'passed' => Tab::make(__('Passées'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_status', OrderStatus::Passed->value))
                ->badge(SupplierOrder::query()->where('order_status', OrderStatus::Passed->value)->count()),

            'confirmed' => Tab::make(__('Confirmée'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_status', OrderStatus::Confirmed->value))
                ->badge(SupplierOrder::query()->where('order_status', OrderStatus::Confirmed->value)->count()),

            'delivered' => Tab::make(__('Livrées'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_status', OrderStatus::Delivered->value))
                ->badge(SupplierOrder::query()->where('order_status', OrderStatus::Delivered->value)->count()),

            'checked' => Tab::make(__('Contrôlées'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_status', OrderStatus::Checked->value))
                ->badge(SupplierOrder::query()->where('order_status', OrderStatus::Checked->value)->count()),

            'stock-missing' => Tab::make(__('Stock manquant'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('order_status', OrderStatus::Checked->value)
                    ->whereHas('supplier_order_items', fn (Builder $itemsQuery): Builder => $itemsQuery->whereNull('moved_to_stock_at')))
                ->badge(SupplierOrder::query()
                    ->where('order_status', OrderStatus::Checked->value)
                    ->whereHas('supplier_order_items', fn (Builder $itemsQuery): Builder => $itemsQuery->whereNull('moved_to_stock_at'))
                    ->count()),

        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        if (SupplierOrder::query()
            ->where('order_status', OrderStatus::Checked->value)
            ->whereHas('supplier_order_items', fn (Builder $itemsQuery): Builder => $itemsQuery->whereNull('moved_to_stock_at'))
            ->exists()) {
            return 'stock-missing';
        }

        foreach (['delivered', 'confirmed', 'passed', 'checked', 'draft'] as $tab) {
            $status = match ($tab) {
                'delivered' => OrderStatus::Delivered,
                'confirmed' => OrderStatus::Confirmed,
                'passed' => OrderStatus::Passed,
                'checked' => OrderStatus::Checked,
                'draft' => OrderStatus::Draft,
            };

            if (SupplierOrder::query()->where('order_status', $status->value)->exists()) {
                return $tab;
            }
        }

        return 'all';
    }
}
