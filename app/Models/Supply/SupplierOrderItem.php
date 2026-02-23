<?php

namespace App\Models\Supply;

use App\Models\Production\Production;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierOrderItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_weight' => 'decimal:3',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'allocated_quantity' => 'decimal:3',
            'expiry_date' => 'date',
            'moved_to_stock_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (SupplierOrderItem $item): void {
            $item->syncWaveRequirementStatuses();
        });

        static::deleted(function (SupplierOrderItem $item): void {
            $item->syncWaveRequirementStatuses();
        });
    }

    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }

    public function supplierListing(): BelongsTo
    {
        return $this->belongsTo(SupplierListing::class);
    }

    public function allocatedToProduction(): BelongsTo
    {
        return $this->belongsTo(Production::class, 'allocated_to_production_id');
    }

    public function movedToStockBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_to_stock_by');
    }

    public function supply(): HasOne
    {
        return $this->hasOne(Supply::class);
    }

    public function getRemainingQuantity(): float
    {
        return max(0, $this->quantity - ($this->allocated_quantity ?? 0));
    }

    public function isInSupplies(): bool
    {
        return $this->is_in_supplies === 'Stock' || $this->moved_to_stock_at !== null;
    }

    public function syncWaveRequirementStatuses(): void
    {
        $this->loadMissing('supplierOrder.wave');

        $this->supplierOrder?->syncWaveRequirementStatuses();
    }
}
