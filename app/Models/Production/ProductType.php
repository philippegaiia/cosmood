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
}
