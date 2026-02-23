<?php

namespace App\Models\Production;

use App\Enums\QcInputType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QcTemplateItem extends Model
{
    /** @use HasFactory<\Database\Factories\Production\QcTemplateItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_type' => QcInputType::class,
            'required' => 'boolean',
            'min_value' => 'decimal:3',
            'max_value' => 'decimal:3',
            'options' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function qcTemplate(): BelongsTo
    {
        return $this->belongsTo(QcTemplate::class);
    }

    public function productionChecks(): HasMany
    {
        return $this->hasMany(ProductionQcCheck::class);
    }
}
