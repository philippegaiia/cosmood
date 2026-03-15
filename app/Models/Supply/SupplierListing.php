<?php

namespace App\Models\Supply;

use App\Enums\IngredientBaseUnit;
use App\Enums\Packaging;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierListing extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (SupplierListing $listing): void {
            $listing->unit_of_measure = self::normalizeUnitOfMeasure((string) ($listing->unit_of_measure ?? 'kg'));
        });
    }

    protected $fillable = [
        'id',
        'name',
        'code',
        'supplier_code',
        'pkg',
        'unit_weight',
        'price',
        'organic',
        'fairtrade',
        'cosmos',
        'ecocert',
        'description',
        'is_active',
        'supplier_id',
        'ingredient_id',
        'file_path',
        'unit_of_measure',
    ];

    protected $casts = [
        'pkg' => Packaging::class,
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplies(): HasMany
    {
        return $this->hasMany(Supply::class);
    }

    public function supplier_order_items(): HasMany
    {
        return $this->hasMany(SupplierOrderItem::class);
    }

    public static function normalizeUnitOfMeasure(string $unitOfMeasure): string
    {
        $normalized = mb_strtolower(trim($unitOfMeasure));

        return match ($normalized) {
            'u', 'unit', 'units' => 'u',
            default => trim($unitOfMeasure) !== '' ? trim($unitOfMeasure) : 'kg',
        };
    }

    public function getNormalizedUnitOfMeasure(): string
    {
        return self::normalizeUnitOfMeasure((string) ($this->unit_of_measure ?? 'kg'));
    }

    public function isUnitBased(): bool
    {
        $this->loadMissing('ingredient:id,base_unit');

        return $this->ingredient?->base_unit === IngredientBaseUnit::Unit
            || $this->getNormalizedUnitOfMeasure() === 'u';
    }
}
