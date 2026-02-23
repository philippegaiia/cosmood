<?php

namespace App\Models\Production;

use App\Enums\Phases;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\Supply;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'organic' => 'boolean',
        'is_supplied' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductionItem $item): void {
            if ($item->supply_id !== null) {
                $item->is_supplied = true;
            }
        });
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function production_task(): BelongsTo
    {
        return $this->supplierListing();
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function supplierListing(): BelongsTo
    {
        return $this->belongsTo(SupplierListing::class);
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function getPhaseLabel(): string
    {
        return Phases::tryFrom((string) $this->phase)?->getLabel() ?? (string) $this->phase;
    }

    public function getCalculatedQuantityKg(): float
    {
        $plannedQuantity = (float) ($this->production?->planned_quantity ?? 0);
        $percentage = (float) ($this->percentage_of_oils ?? 0);

        return round(($plannedQuantity * $percentage) / 100, 3);
    }

    public function getReferenceUnitPrice(): ?float
    {
        $supplyUnitPrice = $this->supply?->unit_price;

        if ($supplyUnitPrice !== null) {
            return (float) $supplyUnitPrice;
        }

        $listingPrice = $this->supplierListing?->price;

        if ($listingPrice !== null) {
            return (float) $listingPrice;
        }

        $ingredientPrice = $this->ingredient?->price;

        if ($ingredientPrice !== null) {
            return (float) $ingredientPrice;
        }

        return null;
    }

    public function getEstimatedCost(): ?float
    {
        $unitPrice = $this->getReferenceUnitPrice();

        if ($unitPrice === null) {
            return null;
        }

        return round($this->getCalculatedQuantityKg() * $unitPrice, 2);
    }
}
