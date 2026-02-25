<?php

namespace App\Models\Supply;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ingredient extends Model
{
    use HasFactory;
    use SoftDeletes;

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
        $total = Supply::query()
            ->whereHas('supplierListing', fn ($query) => $query->where('ingredient_id', $this->id))
            ->selectRaw('COALESCE(SUM(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0) - COALESCE(allocated_quantity, 0)), 0) as total')
            ->value('total');

        return round((float) ($total ?? 0), 3);
    }

    /*public function formula_items(): HasMany
    {
        return $this->hasMany(FormulaItem::class);
    }*/
}
