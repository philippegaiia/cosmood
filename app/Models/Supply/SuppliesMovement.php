<?php

namespace App\Models\Supply;

use App\Models\Production\Production;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuppliesMovement extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'meta' => 'array',
            'moved_at' => 'datetime',
        ];
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function supplierOrderItem(): BelongsTo
    {
        return $this->belongsTo(SupplierOrderItem::class);
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
