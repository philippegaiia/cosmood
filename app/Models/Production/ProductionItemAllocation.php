<?php

namespace App\Models\Production;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents an explicit allocation of a supply lot to a production item.
 *
 * An item can have multiple allocations from different supplies.
 * Each allocation tracks its own status (reserved, consumed, released).
 */
class ProductionItemAllocation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'reserved_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function productionItem(): BelongsTo
    {
        return $this->belongsTo(ProductionItem::class);
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Supply\Supply::class);
    }

    public function isReserved(): bool
    {
        return $this->status === 'reserved';
    }

    public function isConsumed(): bool
    {
        return $this->status === 'consumed';
    }

    public function isReleased(): bool
    {
        return $this->status === 'released';
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['reserved', 'consumed'], true);
    }
}
