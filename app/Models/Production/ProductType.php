<?php

namespace App\Models\Production;

use App\Enums\SizingMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductType extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sizing_mode' => SizingMode::class,
            'default_batch_size' => 'decimal:3',
            'expected_waste_kg' => 'decimal:3',
            'unit_fill_size' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function batchSizePresets(): HasMany
    {
        return $this->hasMany(BatchSizePreset::class);
    }

    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function qcTemplate(): BelongsTo
    {
        return $this->belongsTo(QcTemplate::class);
    }

    public function defaultProductionLine(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class, 'default_production_line_id');
    }

    /**
     * Production lines that are allowed for this product type.
     *
     * When this set is non-empty, productions of this type may only be assigned
     * to lines within this set. If the set is empty, no product-type line
     * restriction is enforced (backward-compatible default).
     */
    public function allowedProductionLines(): BelongsToMany
    {
        return $this->belongsToMany(ProductionLine::class, 'product_type_production_line')
            ->withTimestamps();
    }

    public function taskTemplates(): BelongsToMany
    {
        return $this->belongsToMany(TaskTemplate::class, 'product_type_task_template')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function defaultTaskTemplate(): ?TaskTemplate
    {
        return $this->taskTemplates()->wherePivot('is_default', true)->first();
    }

    public function defaultPreset(): ?BatchSizePreset
    {
        return $this->batchSizePresets()->where('is_default', true)->first();
    }

    /**
     * Whether this product type enforces an allowed-lines restriction.
     *
     * Returns false when no allowed lines are configured, meaning the model-level
     * allowed-line guard stays in open/backward-compatible mode.
     */
    public function hasAllowedProductionLineRestrictions(): bool
    {
        $this->loadMissing('allowedProductionLines');

        return $this->allowedProductionLines->isNotEmpty();
    }

    /**
     * Whether the given production line is permitted for this product type.
     *
     * Always returns true when:
     * - $productionLineId is null (unassigned productions are allowed).
     * - No allowed-line restrictions are configured for this type.
     *
     * @param  int|null  $productionLineId  The ID of the line to check, or null.
     */
    public function allowsProductionLine(?int $productionLineId): bool
    {
        if ($productionLineId === null) {
            return true;
        }

        $this->loadMissing('allowedProductionLines');

        if ($this->allowedProductionLines->isEmpty()) {
            return true;
        }

        return $this->allowedProductionLines->contains('id', $productionLineId);
    }
}
