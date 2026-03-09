<?php

namespace App\Models\Production;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionTaskType extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['id', 'name', 'color', 'slug', 'duration', 'description', 'is_active', 'is_capacity_consuming'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_capacity_consuming' => 'boolean',
        ];
    }

    public function productionTasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class);
    }

    public function taskTemplates(): BelongsToMany
    {
        return $this->belongsToMany(TaskTemplate::class, 'task_template_task_type')
            ->withPivot(['sort_order', 'offset_days', 'skip_weekends', 'duration_override'])
            ->orderByPivot('sort_order');
    }
}
