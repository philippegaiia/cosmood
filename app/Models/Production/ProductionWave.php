<?php

namespace App\Models\Production;

use App\Enums\WaveStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionWave extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => WaveStatus::class,
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function productions(): HasMany
    {
        return $this->hasMany(Production::class, 'production_wave_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === WaveStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === WaveStatus::Approved;
    }

    public function isInProgress(): bool
    {
        return $this->status === WaveStatus::InProgress;
    }

    public function isCompleted(): bool
    {
        return $this->status === WaveStatus::Completed;
    }

    public function isCancelled(): bool
    {
        return $this->status === WaveStatus::Cancelled;
    }

    public function approve(User $user, ?CarbonInterface $plannedStartDate = null, ?CarbonInterface $plannedEndDate = null): void
    {
        if (! $this->isDraft()) {
            throw new \InvalidArgumentException('Only draft waves can be approved');
        }

        $this->update([
            'status' => WaveStatus::Approved,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'planned_start_date' => $plannedStartDate ?? $this->planned_start_date,
            'planned_end_date' => $plannedEndDate ?? $this->planned_end_date,
        ]);
    }

    public function start(): void
    {
        if (! $this->isApproved()) {
            throw new \InvalidArgumentException('Only approved waves can be started');
        }

        $this->update([
            'status' => WaveStatus::InProgress,
            'started_at' => now(),
        ]);
    }

    public function complete(): void
    {
        if (! $this->isInProgress()) {
            throw new \InvalidArgumentException('Only in progress waves can be completed');
        }

        $this->update([
            'status' => WaveStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function cancel(): void
    {
        if ($this->isCompleted()) {
            throw new \InvalidArgumentException('Completed waves cannot be cancelled');
        }

        $this->update([
            'status' => WaveStatus::Cancelled,
        ]);
    }
}
