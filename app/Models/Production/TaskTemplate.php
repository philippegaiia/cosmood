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

    /**
     * Return product-type link state in a shape compatible with the Filament form.
     *
     * @return array<int, array{product_type_id: int, is_default: bool}>
     */
    public function getProductTypeLinksForForm(): array
    {
        $this->loadMissing('productTypes');

        return $this->productTypes
            ->map(fn (ProductType $productType): array => [
                'product_type_id' => (int) $productType->id,
                'is_default' => (bool) ($productType->pivot?->is_default ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * Sync product-type assignments and keep one default template per product type.
     *
     * @param  array<int, array{product_type_id?: mixed, is_default?: mixed}>  $links
     */
    public function syncProductTypeLinks(array $links): void
    {
        $normalizedLinks = collect($links)
            ->map(fn (array $link): ?array => $this->normalizeProductTypeLink($link))
            ->filter()
            ->reverse()
            ->unique('product_type_id')
            ->reverse()
            ->values();

        $syncPayload = $normalizedLinks
            ->mapWithKeys(fn (array $link): array => [
                $link['product_type_id'] => ['is_default' => $link['is_default']],
            ])
            ->all();

        $this->productTypes()->sync($syncPayload);

        $defaultProductTypeIds = $normalizedLinks
            ->filter(fn (array $link): bool => $link['is_default'])
            ->pluck('product_type_id')
            ->all();

        if ($defaultProductTypeIds !== []) {
            $this->productTypes()
                ->newPivotStatement()
                ->whereIn('product_type_id', $defaultProductTypeIds)
                ->where('task_template_id', '!=', $this->getKey())
                ->update([
                    'is_default' => false,
                    'updated_at' => now(),
                ]);
        }

        $this->unsetRelation('productTypes');
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

    /**
     * @param  array{product_type_id?: mixed, is_default?: mixed}  $link
     * @return array{product_type_id: int, is_default: bool}|null
     */
    private function normalizeProductTypeLink(array $link): ?array
    {
        if (! filled($link['product_type_id'] ?? null)) {
            return null;
        }

        $productTypeId = (int) $link['product_type_id'];

        if ($productTypeId <= 0) {
            return null;
        }

        return [
            'product_type_id' => $productTypeId,
            'is_default' => (bool) ($link['is_default'] ?? false),
        ];
    }
}
