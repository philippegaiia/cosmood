<?php

namespace App\Models\Production;

use App\Filament\Resources\Production\ProductionResource;
use App\Models\User;
use Guava\Calendar\Contracts\Eventable;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionTask extends Model implements Eventable
{
    use HasFactory;

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
        $productName = (string) ($this->production?->product?->name ?? __('Sans nom'));
        $taskName = (string) ($this->name ?? __('Tâche'));
        $backgroundColor = $this->productionTaskType?->color ?? '#6b7280';
        $temporaryLot = (string) ($this->production?->batch_number ?? '');
        $permanentLot = (string) ($this->production?->permanent_batch_number ?? '');
        $event = CalendarEvent::make($this)
            ->title($productName)
            ->start($this->scheduled_date)
            ->end($this->scheduled_date)
            ->allDay()
            ->backgroundColor($backgroundColor)
            ->textColor('#ffffff')
            ->extendedProps([
                'productName' => $productName,
                'lotLabel' => $this->resolveCalendarLotLabel(),
                'temporaryLot' => $temporaryLot,
                'permanentLot' => $permanentLot,
                'taskName' => $taskName,
                'eventType' => 'task',
            ])
            ->action('edit');

        $productionUrl = $this->resolveParentProductionUrl();

        if ($productionUrl !== null) {
            $event->url($productionUrl, '_self');
        }

        return $event;
    }

    /**
     * Resolve calendar lot label as permanent lot + temporary lot.
     */
    private function resolveCalendarLotLabel(): string
    {
        $temporaryLot = (string) ($this->production?->batch_number ?? '');
        $permanentLot = (string) ($this->production?->permanent_batch_number ?? '');

        if ($permanentLot === '') {
            return $temporaryLot;
        }

        return $permanentLot.' ('.$temporaryLot.')';
    }

    private function resolveParentProductionUrl(): ?string
    {
        if ($this->production_id === null) {
            return null;
        }

        try {
            return ProductionResource::getUrl('view', ['record' => $this->production_id]);
        } catch (\Throwable) {
            return null;
        }
    }
}
