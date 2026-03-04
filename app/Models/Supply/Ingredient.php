<?php

namespace App\Models\Supply;

use App\Enums\IngredientBaseUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ingredient extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Ingredient $ingredient): void {
            if (empty($ingredient->code)) {
                $ingredient->code = self::generateUniqueCode($ingredient->ingredient_category_id);
            }
        });
    }

    protected $fillable = [
        'id',
        'ingredient_category_id',
        'code',
        'name',
        'name_en',
        'slug',
        'inci',
        'inci_naoh',
        'inci_koh',
        'cas',
        'cas_einecs',
        'einecs',
        'is_active',
        'base_unit',
        'is_manufactured',
        'description',
        'price',
        'stock_min',
        'deleted_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock_min' => 'decimal:3',
            'base_unit' => IngredientBaseUnit::class,
            'is_manufactured' => 'boolean',
        ];
    }

    public function ingredient_category(): BelongsTo
    {
        return $this->belongsTo(IngredientCategory::class, 'ingredient_category_id');
    }

    public function supplier_listings(): HasMany
    {
        return $this->hasMany(SupplierListing::class);
    }

    public function getTotalAvailableStock(): float
    {
        return round(max(0, $this->getTotalPhysicalStock() - $this->getTotalAllocatedStock()), 3);
    }

    /**
     * Get total physical stock (received - consumed) across all lots.
     * Only includes supplies that are marked as in stock.
     */
    public function getTotalPhysicalStock(): float
    {
        $total = Supply::query()
            ->whereHas('supplierListing', fn ($query) => $query->where('ingredient_id', $this->id))
            ->where('is_in_stock', true)
            ->selectRaw('COALESCE(SUM(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0)), 0) as total')
            ->value('total');

        return round((float) ($total ?? 0), 3);
    }

    /**
     * Get total allocated stock across all lots.
     * Only includes supplies that are marked as in stock.
     * Calculates from movements instead of cached field for accuracy.
     */
    public function getTotalAllocatedStock(): float
    {
        $supplyIds = Supply::query()
            ->whereHas('supplierListing', fn ($query) => $query->where('ingredient_id', $this->id))
            ->where('is_in_stock', true)
            ->pluck('id');

        if ($supplyIds->isEmpty()) {
            return 0.0;
        }

        $total = \App\Models\Supply\SuppliesMovement::query()
            ->whereIn('supply_id', $supplyIds)
            ->where('movement_type', 'allocation')
            ->sum('quantity');

        return round((float) $total, 3);
    }

    /**
     * Get count of supply lots for this ingredient.
     */
    public function getSupplyLotsCount(): int
    {
        return Supply::query()
            ->whereHas('supplierListing', fn ($query) => $query->where('ingredient_id', $this->id))
            ->count();
    }

    /**
     * Get all supplies for this ingredient.
     */
    public function supplies(): HasMany
    {
        return $this->hasManyThrough(
            Supply::class,
            SupplierListing::class,
            'ingredient_id',
            'supplier_listing_id'
        );
    }

    /*public function formula_items(): HasMany
    {
        return $this->hasMany(FormulaItem::class);
    }*/

    /**
     * Generate a unique ingredient code based on category code.
     *
     * Format: CATEGORY-XXX (e.g., OIL-001, ACT-042)
     * If no category or category has no code, uses ING-XXXX format.
     */
    public static function generateUniqueCode(?int $categoryId): string
    {
        if (! $categoryId) {
            return self::generateGenericCode();
        }

        $category = IngredientCategory::query()->find($categoryId);

        if (! $category || empty($category->code)) {
            return self::generateGenericCode();
        }

        $prefix = strtoupper($category->code);
        $nextSequence = self::getNextSequenceForPrefix($prefix);

        return $prefix.str_pad((string) $nextSequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a generic code when no category code is available.
     */
    private static function generateGenericCode(): string
    {
        $nextId = (self::max('id') ?? 0) + 1;

        return 'ING'.str_pad((string) $nextId, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get the next sequence number for a category prefix.
     */
    private static function getNextSequenceForPrefix(string $prefix): int
    {
        $maxSequence = self::query()
            ->where('code', 'like', $prefix.'%')
            ->get()
            ->map(function ($ingredient) use ($prefix) {
                // Extract the numeric part after the prefix
                $pattern = '/^'.preg_quote($prefix, '/').'(\d+)$/';
                if (preg_match($pattern, $ingredient->code, $matches)) {
                    return (int) $matches[1];
                }

                return 0;
            })
            ->max() ?? 0;

        return $maxSequence + 1;
    }
}
