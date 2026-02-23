<?php

namespace App\Models\Production;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionTask extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'scheduled_date' => 'date',
            'duration_minutes' => 'integer',
            'sequence_order' => 'integer',
            'is_finished' => 'boolean',
            'is_manual_schedule' => 'boolean',
            'dependency_bypassed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function productionTaskType(): BelongsTo
    {
        return $this->belongsTo(ProductionTaskType::class);
    }

    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(TaskTemplateItem::class, 'task_template_item_id');
    }

    public function dependencyBypassedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dependency_bypassed_by');
    }

    public function isFromTemplate(): bool
    {
        return $this->source === 'template';
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function cancel(?string $reason = null): void
    {
        $this->update([
            'cancelled_at' => now(),
            'cancelled_reason' => $reason,
        ]);
    }
}
