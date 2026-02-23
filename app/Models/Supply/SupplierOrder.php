<?php

namespace App\Models\Supply;

use App\Enums\OrderStatus;
use App\Models\Production\ProductionWave;
use App\Services\Production\WaveRequirementStatusService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierOrder extends Model
{
    use HasFactory;
    use SoftDeletes;

    /*protected $filllable = [
        'supplier_id','order_status','order_ref','order_date','delivery_date','confirmation_number','invoice_number','bl_number','freight_cost','description', 'series'
    ];*/

    protected $guarded = [

    ];

    protected $casts = [
        'order_status' => OrderStatus::class,
    ];

    protected static function booted(): void
    {
        static::updated(function (SupplierOrder $order): void {
            if (! $order->wasChanged('order_status')) {
                return;
            }

            if (! in_array($order->order_status, [OrderStatus::Confirmed, OrderStatus::Checked], true)) {
                return;
            }

            $order->supplier_order_items()
                ->with('supplierListing.ingredient')
                ->get()
                ->each(function (SupplierOrderItem $item): void {
                    if ($item->unit_price === null) {
                        return;
                    }

                    $ingredient = $item->supplierListing?->ingredient;

                    if (! $ingredient) {
                        return;
                    }

                    $ingredient->update([
                        'price' => $item->unit_price,
                    ]);
                });

        });

        static::saved(function (SupplierOrder $order): void {
            $order->syncWaveRequirementStatuses();
        });

        static::deleted(function (SupplierOrder $order): void {
            $order->syncWaveRequirementStatuses();
        });
    }

    public function supplier_order_items(): HasMany
    {
        return $this->hasMany(SupplierOrderItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(ProductionWave::class, 'production_wave_id');
    }

    public function supplier_listings(): HasManyThrough
    {
        return $this->hasManyThrough(SupplierListing::class, SupplierOrderItem::class);
    }

    public function syncWaveRequirementStatuses(): void
    {
        $this->loadMissing('wave');

        if (! $this->wave) {
            return;
        }

        app(WaveRequirementStatusService::class)->syncForWave($this->wave);
    }
}
