<?php

namespace App\Models\Supply;

use App\Models\Production\Production;
use App\Models\Production\ProductionItemAllocation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class Supply extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'initial_quantity' => 'decimal:3',
            'quantity_in' => 'decimal:3',
            'quantity_out' => 'decimal:3',
            'allocated_quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'expiry_date' => 'date',
            'delivery_date' => 'date',
            'is_in_stock' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Supply $supply): void {
            if (filled($supply->initial_quantity) && (float) $supply->initial_quantity < 0) {
                throw new InvalidArgumentException(__('La quantité initiale ne peut pas être négative.'));
            }

            if (filled($supply->quantity_in) && (float) $supply->quantity_in < 0) {
                throw new InvalidArgumentException(__('La quantité reçue ne peut pas être négative.'));
            }

            if (filled($supply->quantity_out) && (float) $supply->quantity_out < 0) {
                throw new InvalidArgumentException(__('La quantité consommée ne peut pas être négative.'));
            }

            if (filled($supply->unit_price) && (float) $supply->unit_price < 0) {
                throw new InvalidArgumentException(__('Le prix unitaire ne peut pas être négatif.'));
            }
        });
    }

    public function supplierListing(): BelongsTo
    {
        return $this->belongsTo(SupplierListing::class);
    }

    public function supplierOrderItem(): BelongsTo
    {
        return $this->belongsTo(SupplierOrderItem::class);
    }

    public function sourceProduction(): BelongsTo
    {
        return $this->belongsTo(Production::class, 'source_production_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ProductionItemAllocation::class);
    }

    /**
     * Get all stock movements for this supply.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(SuppliesMovement::class);
    }

    /**
     * Calculate allocated quantity from stock movements.
     *
     * Uses double-entry accounting: sums all allocation movements (positive for reservations,
     * negative for releases). This ensures accurate tracking even when supplies are marked
     * as out-of-stock.
     *
     * @return float The net allocated quantity (can be negative if more releases than allocations)
     */
    public function getAllocatedQuantity(): float
    {
        return $this->movements()
            ->where('movement_type', 'allocation')
            ->sum('quantity');
    }

    /**
     * Calculate available quantity for allocation.
     *
     * Available = (quantity_in + initial_quantity) - quantity_out - allocated_quantity
     *
     * Note: This calculation does NOT check is_in_stock. The is_in_stock flag is used
     * at the query/filter level to exclude supplies from availability lists.
     *
     * @return float Available quantity (never negative)
     */
    public function getAvailableQuantity(): float
    {
        $stockIn = $this->quantity_in ?? $this->initial_quantity ?? 0;
        $allocated = $this->getAllocatedQuantity();

        return max(0, $stockIn - ($this->quantity_out ?? 0) - $allocated);
    }

    public function getTotalQuantity(): float
    {
        $stockIn = $this->quantity_in ?? $this->initial_quantity ?? 0;

        return $stockIn - ($this->quantity_out ?? 0);
    }

    public function getUnitOfMeasure(): string
    {
        $supplierListing = $this->relationLoaded('supplierListing')
            ? $this->supplierListing
            : ($this->supplier_listing_id
                ? SupplierListing::query()->select(['id', 'unit_of_measure'])->find($this->supplier_listing_id)
                : null);

        return (string) ($supplierListing?->unit_of_measure ?: 'kg');
    }
}
