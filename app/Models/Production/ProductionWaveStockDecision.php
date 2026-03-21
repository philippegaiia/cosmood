<?php

namespace App\Models\Production;

use App\Models\Production\Concerns\BumpsParentProductionWaveVersion;
use App\Models\Supply\Ingredient;
use Database\Factories\Production\ProductionWaveStockDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionWaveStockDecision extends Model
{
    use BumpsParentProductionWaveVersion;

    /** @use HasFactory<ProductionWaveStockDecisionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reserved_quantity' => 'decimal:3',
        ];
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(ProductionWave::class, 'production_wave_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
