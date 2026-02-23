<?php

namespace App\Models\Production;

use App\Enums\Phases;
use App\Models\Supply\Ingredient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormulaItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'phase' => Phases::class,
    ];

    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
