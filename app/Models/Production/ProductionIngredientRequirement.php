<?php

namespace App\Models\Production;

use App\Enums\RequirementStatus;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\Supply;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionIngredientRequirement extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'required_quantity' => 'decimal:3',
            'allocated_quantity' => 'decimal:3',
            'status' => RequirementStatus::class,
            'is_collapsed_in_ui' => 'boolean',
        ];
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(ProductionWave::class, 'production_wave_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function supplierListing(): BelongsTo
    {
        return $this->belongsTo(SupplierListing::class);
    }

    public function allocatedFromSupply(): BelongsTo
    {
        return $this->belongsTo(Supply::class, 'allocated_from_supply_id');
    }

    public function fulfilledByMasterbatch(): BelongsTo
    {
        return $this->belongsTo(Production::class, 'fulfilled_by_masterbatch_id');
    }

    public function isAllocated(): bool
    {
        return $this->status === RequirementStatus::Allocated;
    }

    public function isFulfilledByMasterbatch(): bool
    {
        return $this->fulfilled_by_masterbatch_id !== null;
    }

    public function getRemainingQuantity(): float
    {
        return max(0, $this->required_quantity - $this->allocated_quantity);
    }
}
