<?php

namespace App\Models\Supply;

use App\Enums\OrderStatus;
use App\Models\Production\ProductionWave;
use App\Services\OptimisticLocking\AggregateVersionService;
use App\Services\Production\WaveRequirementStatusService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use InvalidArgumentException;

class SupplierOrder extends Model
{
    use HasFactory;

    /*protected $filllable = [
        'supplier_id','order_status','order_ref','order_date','delivery_date','confirmation_number','invoice_number','bl_number','freight_cost','description', 'series'
    ];*/

    protected $guarded = [

    ];

    protected function casts(): array
    {
        return [
            'order_status' => OrderStatus::class,
            'order_date' => 'date',
            'delivery_date' => 'date',
            'lock_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SupplierOrder $order): void {
            if (blank($order->serial_number)) {
                $order->serial_number = (int) (self::max('serial_number') ?? 0) + 1;
            }

            if (blank($order->order_ref) && filled($order->supplier_id)) {
                $supplierCode = Supplier::query()
                    ->whereKey($order->supplier_id)
                    ->value('code');

                if (filled($supplierCode)) {
                    $order->order_ref = now()->year.'-'.$supplierCode.'-'.$order->serial_number;
                }
            }
        });

        static::updating(function (SupplierOrder $order): void {
            if (! $order->isDirty('order_status')) {
                return;
            }

            $fromRaw = $order->getRawOriginal('order_status');
            $toRaw = $order->order_status;

            $from = $fromRaw instanceof OrderStatus ? $fromRaw : OrderStatus::tryFrom((string) $fromRaw);
            $to = $toRaw instanceof OrderStatus ? $toRaw : OrderStatus::tryFrom((string) $toRaw);

            if (! $from instanceof OrderStatus || ! $to instanceof OrderStatus) {
                return;
            }

            if (! self::canTransition($from, $to)) {
                throw new InvalidArgumentException(__('Transition de statut invalide de :from vers :to.', [
                    'from' => $from->getLabel(),
                    'to' => $to->getLabel(),
                ]));
            }
        });

        static::updated(function (SupplierOrder $order): void {
            if (! $order->wasChanged('lock_version')) {
                app(AggregateVersionService::class)->bumpSupplierOrderVersion($order);
            }

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
            if ($order->wasRecentlyCreated) {
                app(AggregateVersionService::class)->bumpProductionWaveVersionIfExists((int) $order->production_wave_id);
            }

            if ($order->wasChanged('production_wave_id')) {
                app(AggregateVersionService::class)->bumpProductionWaveVersionIfExists((int) ($order->getRawOriginal('production_wave_id') ?? 0));
            }

            $order->resetCommittedQuantitiesWhenWaveRemoved();
            $order->syncWaveRequirementStatuses();
        });

        static::deleting(function (SupplierOrder $order): void {
            if ($order->supplier_order_items()->exists()) {
                throw new InvalidArgumentException(__('Cette commande contient des ingrédients commandés. Supprimez-les avant de supprimer la commande.'));
            }
        });

        static::deleted(function (SupplierOrder $order): void {
            app(AggregateVersionService::class)->bumpProductionWaveVersionIfExists((int) ($order->production_wave_id ?? 0));
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
        if ($this->wasChanged('production_wave_id')) {
            $originalWaveId = (int) ($this->getRawOriginal('production_wave_id') ?? 0);

            if ($originalWaveId > 0) {
                $originalWave = ProductionWave::query()->find($originalWaveId);

                if ($originalWave) {
                    app(WaveRequirementStatusService::class)->syncForWave($originalWave);
                }
            }
        }

        $this->loadMissing('wave');

        if (! $this->wave) {
            return;
        }

        app(WaveRequirementStatusService::class)->syncForWave($this->wave);
    }

    private function resetCommittedQuantitiesWhenWaveRemoved(): void
    {
        if (! $this->wasChanged('production_wave_id') || $this->production_wave_id !== null) {
            return;
        }

        $this->supplier_order_items()
            ->where('committed_quantity_kg', '>', 0)
            ->update(['committed_quantity_kg' => 0]);
    }

    public static function transitionMap(): array
    {
        return [
            OrderStatus::Draft->value => [
                OrderStatus::Passed,
                OrderStatus::Cancelled,
            ],
            OrderStatus::Passed->value => [
                OrderStatus::Confirmed,
                OrderStatus::Delivered,
                OrderStatus::Cancelled,
            ],
            OrderStatus::Confirmed->value => [
                OrderStatus::Delivered,
                OrderStatus::Cancelled,
            ],
            OrderStatus::Delivered->value => [
                OrderStatus::Checked,
                OrderStatus::Cancelled,
            ],
            OrderStatus::Checked->value => [],
            OrderStatus::Cancelled->value => [],
        ];
    }

    public static function canTransition(OrderStatus $from, OrderStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::transitionMap()[$from->value] ?? [], true);
    }

    /**
     * @return array<int, OrderStatus>
     */
    public static function allowedTransitionsFor(OrderStatus $from): array
    {
        return array_merge([$from], self::transitionMap()[$from->value] ?? []);
    }
}
