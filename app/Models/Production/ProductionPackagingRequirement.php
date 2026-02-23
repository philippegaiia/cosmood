<?php

namespace App\Models\Production;

use App\Enums\RequirementStatus;
use App\Models\Supply\Supplier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionPackagingRequirement extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:3',
            'quantity_per_unit' => 'decimal:4',
            'status' => RequirementStatus::class,
        ];
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(ProductionWave::class, 'production_wave_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function isAllocated(): bool
    {
        return $this->status === RequirementStatus::Allocated;
    }

    public function getRemainingQuantity(): int
    {
        return max(0, $this->required_quantity - $this->allocated_quantity);
    }
}
