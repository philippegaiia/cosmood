<?php

namespace App\Models\Supply;

use App\Models\Production\Production;
use App\Models\Production\ProductionIngredientRequirement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        ];
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

    public function ingredientRequirements(): HasMany
    {
        return $this->hasMany(ProductionIngredientRequirement::class, 'allocated_from_supply_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SuppliesMovement::class);
    }

    public function getAvailableQuantity(): float
    {
        $stockIn = $this->quantity_in ?? $this->initial_quantity ?? 0;

        return max(0, $stockIn - ($this->quantity_out ?? 0) - ($this->allocated_quantity ?? 0));
    }

    public function getTotalQuantity(): float
    {
        $stockIn = $this->quantity_in ?? $this->initial_quantity ?? 0;

        return $stockIn - ($this->quantity_out ?? 0);
    }
}
