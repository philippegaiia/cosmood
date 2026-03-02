<?php

namespace App\Models\Production;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class TaskTemplateTaskType extends Pivot
{
    public $incrementing = true;

    protected $table = 'task_template_task_type';

    protected $fillable = [
        'task_template_id',
        'production_task_type_id',
        'sort_order',
        'offset_days',
        'skip_weekends',
        'duration_override',
    ];

    protected $casts = [
        'skip_weekends' => 'boolean',
        'sort_order' => 'integer',
        'offset_days' => 'integer',
        'duration_override' => 'integer',
    ];

    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    public function taskType(): BelongsTo
    {
        return $this->belongsTo(ProductionTaskType::class, 'production_task_type_id');
    }
}
