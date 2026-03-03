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
}
