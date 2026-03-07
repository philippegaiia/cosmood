<?php

namespace App\Models\Production;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormulaProduct extends Pivot
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'formula_product';

    public $incrementing = true;

    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
