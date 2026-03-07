<?php

namespace App\Models\Production;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskTemplate extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    private ?int $legacyProductTypeId = null;

    private ?bool $legacyIsDefault = null;

    protected static function booted(): void
    {
        static::created(function (self $template): void {
            $template->syncLegacyProductTypeLink();
        });
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function productTypes(): BelongsToMany
    {
        return $this->belongsToMany(ProductType::class, 'product_type_task_template')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function productType(): BelongsToMany
    {
        return $this->productTypes();
    }

    public function items(): HasMany
    {
        return $this->hasMany(TaskTemplateItem::class)->orderBy('sort_order');
    }

    public function taskTypes(): BelongsToMany
    {
        return $this->belongsToMany(ProductionTaskType::class, 'task_template_task_type')
            ->withPivot(['sort_order', 'offset_days', 'skip_weekends', 'duration_override'])
            ->orderByPivot('sort_order');
    }

    public function taskTemplateTaskTypes(): HasMany
    {
        return $this->hasMany(TaskTemplateTaskType::class)->orderBy('sort_order');
    }

    public function productionTasks(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProductionTask::class,
            TaskTemplateItem::class,
            'task_template_id',
            'task_template_item_id'
        );
    }

    public function setProductTypeIdAttribute(mixed $value): void
    {
        $this->legacyProductTypeId = $value !== null ? (int) $value : null;
    }

    public function getProductTypeIdAttribute(): ?int
    {
        return $this->legacyProductTypeId ?? $this->productTypes()->value('product_types.id');
    }

    public function setIsDefaultAttribute(mixed $value): void
    {
        $this->legacyIsDefault = $value !== null ? (bool) $value : null;
    }

    public function getIsDefaultAttribute(): bool
    {
        if ($this->legacyIsDefault !== null) {
            return $this->legacyIsDefault;
        }

        return $this->productTypes()->wherePivot('is_default', true)->exists();
    }

    private function syncLegacyProductTypeLink(): void
    {
        if ($this->legacyProductTypeId === null) {
            return;
        }

        $isDefault = $this->legacyIsDefault ?? false;

        $this->productTypes()->syncWithoutDetaching([
            $this->legacyProductTypeId => ['is_default' => $isDefault],
        ]);

        if (! $isDefault) {
            return;
        }

        $otherProductTypeIds = $this->productTypes()
            ->where('product_types.id', '!=', $this->legacyProductTypeId)
            ->pluck('product_types.id');

        foreach ($otherProductTypeIds as $productTypeId) {
            $this->productTypes()->updateExistingPivot((int) $productTypeId, ['is_default' => false]);
        }
    }
}
