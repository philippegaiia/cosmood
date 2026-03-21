<?php

namespace App\Models\Supply\Concerns;

use App\Models\Supply\SupplierOrder;
use App\Services\OptimisticLocking\AggregateVersionService;
use App\Services\OptimisticLocking\OptimisticLockingContext;

trait BumpsParentSupplierOrderVersion
{
    protected static function bootBumpsParentSupplierOrderVersion(): void
    {
        static::saved(function (self $model): void {
            $model->bumpParentSupplierOrderVersion();
        });

        static::deleted(function (self $model): void {
            $model->bumpParentSupplierOrderVersion();
        });
    }

    protected function bumpParentSupplierOrderVersion(): void
    {
        $order = $this->getSupplierOrderForVersionBump();

        if (! $order) {
            return;
        }

        if (app(OptimisticLockingContext::class)->shouldSuppressSupplierOrderBump((int) $order->getKey())) {
            return;
        }

        app(AggregateVersionService::class)->bumpSupplierOrderVersion($order);
    }

    protected function getSupplierOrderForVersionBump(): ?SupplierOrder
    {
        if (method_exists($this, 'supplierOrder')) {
            if ($this->relationLoaded('supplierOrder') && $this->supplierOrder) {
                return $this->supplierOrder;
            }

            if (isset($this->supplier_order_id) && $this->supplier_order_id) {
                return SupplierOrder::query()->find($this->supplier_order_id);
            }
        }

        return null;
    }
}
