<?php

namespace App\Services\OptimisticLocking;

use Closure;

class OptimisticLockingContext
{
    /**
     * @var array<int, true>
     */
    private array $suppressedSupplierOrderIds = [];

    public function runWithoutSupplierOrderBumps(int $orderId, Closure $callback): mixed
    {
        $this->suppressedSupplierOrderIds[$orderId] = true;

        try {
            return $callback();
        } finally {
            unset($this->suppressedSupplierOrderIds[$orderId]);
        }
    }

    public function shouldSuppressSupplierOrderBump(int $orderId): bool
    {
        return isset($this->suppressedSupplierOrderIds[$orderId]);
    }
}
