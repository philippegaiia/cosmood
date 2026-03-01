<?php

namespace App\Models\Production;

use App\Models\User;
use Guava\Calendar\Contracts\Eventable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionTask extends Model implements Eventable
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

    /**
     * Convert to calendar event for Guava Calendar.
     */
    public function toCalendarEvent(): CalendarEvent
    {
        $backgroundColor = $this->productionTaskType?->color ?? '#6366f1';

        // If parent production is ongoing, make it visually distinct (lighter)
        if ($this->production?->status === ProductionStatus::Ongoing) {
            $backgroundColor = $this->lightenColor($backgroundColor);
        }

        return CalendarEvent::make($this)
            ->title($this->name ?? 'Tâche')
            ->start($this->scheduled_date)
            ->end($this->scheduled_date)
            ->allDay()
            ->backgroundColor($backgroundColor)
            ->textColor('#ffffff')
            ->action('edit');
    }

    /**
     * Lighten a hex color by blending with white.
     */
    private function lightenColor(string $color): string
    {
        $r = hexdec(substr($color, 1, 2));
        $g = hexdec(substr($color, 3, 2));
        $b = hexdec(substr($color, 5, 2));

        // Blend with white (80% original, 20% white)
        $r = intval($r * 0.8 + 255 * 0.2);
        $g = intval($g * 0.8 + 255 * 0.2);
        $b = intval($b * 0.8 + 255 * 0.2);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
