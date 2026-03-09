<?php

namespace App\Models\Production;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionLine extends Model
{
    /** @use HasFactory<\Database\Factories\Production\ProductionLineFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'daily_batch_capacity' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function productTypes(): HasMany
    {
        return $this->hasMany(ProductType::class, 'default_production_line_id');
    }

    /**
     * Product types for which this line is in the allowed set.
     *
     * Inverse of ProductType::allowedProductionLines().
     */
    public function allowedProductTypes(): BelongsToMany
    {
        return $this->belongsToMany(ProductType::class, 'product_type_production_line')
            ->withTimestamps();
    }

    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }

    public function resolveDailyCapacity(): int
    {
        return max(1, (int) $this->daily_batch_capacity);
    }
}
