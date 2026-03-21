<?php

namespace App\Models\Supply;

use App\Models\Production\Production;
use App\Models\Supply\Concerns\BumpsParentSupplierOrderVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

class SupplierOrderItem extends Model
{
    use BumpsParentSupplierOrderVersion;
    use HasFactory;

    private const LOCKED_AFTER_STOCK_FIELDS = [
        'supplier_listing_id',
        'quantity',
        'unit_weight',
        'unit_price',
        'batch_number',
        'expiry_date',
        'committed_quantity_kg',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_weight' => 'decimal:3',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'allocated_quantity' => 'decimal:3',
            'committed_quantity_kg' => 'decimal:3',
            'expiry_date' => 'date',
            'moved_to_stock_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SupplierOrderItem $item): void {
            if ($item->exists && $item->isLockedForEditingAfterStock() && $item->isDirty(self::LOCKED_AFTER_STOCK_FIELDS)) {
                throw new InvalidArgumentException(__('Cette ligne est déjà passée en stock et ne peut plus être modifiée.'));
            }

            $item->loadMissing('supplierListing.ingredient');

            $orderedUnits = round((float) ($item->quantity ?? 0), 3);

            if ($item->isUnitBased() && abs($orderedUnits - round($orderedUnits)) > 0.0001) {
                throw new InvalidArgumentException(__('La quantité commandée doit être un nombre entier pour les ingrédients unitaires.'));
            }

            if ($orderedUnits <= 0) {
                throw new InvalidArgumentException(__('La quantité commandée doit être supérieure à zéro.'));
            }

            $item->quantity = $item->isUnitBased()
                ? (float) round($orderedUnits)
                : $orderedUnits;

            $orderedQuantityKg = $item->getOrderedQuantityKg();
            $committedQuantityKg = round((float) ($item->committed_quantity_kg ?? 0), 3);

            if ($item->isUnitBased() && abs($committedQuantityKg - round($committedQuantityKg)) > 0.0001) {
                throw new InvalidArgumentException(__('La quantité engagée doit être un nombre entier pour les ingrédients unitaires.'));
            }

            $item->committed_quantity_kg = $committedQuantityKg;

            if ($committedQuantityKg < 0) {
                throw new InvalidArgumentException(__('La quantité engagée ne peut pas être négative.'));
            }

            if ($committedQuantityKg > $orderedQuantityKg) {
                throw new InvalidArgumentException(__('La quantité engagée (:committed kg) ne peut pas dépasser la quantité commandée (:ordered kg).', [
                    'committed' => number_format($committedQuantityKg, 3, ',', ' '),
                    'ordered' => number_format($orderedQuantityKg, 3, ',', ' '),
                ]));
            }
        });

        static::saved(function (SupplierOrderItem $item): void {
            $item->syncWaveRequirementStatuses();
        });

        static::deleting(function (SupplierOrderItem $item): void {
            if ($item->isInSupplies() || $item->supply()->exists()) {
                throw new InvalidArgumentException(__('Cet ingrédient commandé est déjà passé en stock. Supprimez d\'abord le lot correspondant.'));
            }
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

    public function getOrderedQuantityKg(): float
    {
        $quantity = (float) ($this->quantity ?? 0);
        $unitWeight = (float) ($this->unit_weight ?? 0);
        $unitMultiplier = $unitWeight > 0 ? $unitWeight : 1;

        return round($quantity * $unitMultiplier, 3);
    }

    public function isUnitBased(): bool
    {
        $this->loadMissing('supplierListing.ingredient');

        return $this->supplierListing?->isUnitBased() ?? false;
    }

    public function getDisplayUnit(): string
    {
        $this->loadMissing('supplierListing');

        return $this->supplierListing?->getNormalizedUnitOfMeasure() ?? 'kg';
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

    private function isLockedForEditingAfterStock(): bool
    {
        return filled($this->getRawOriginal('moved_to_stock_at'))
            || $this->getRawOriginal('is_in_supplies') === 'Stock';
    }
}
