<?php

namespace App\Models\Production;

use App\Models\Supply\Ingredient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductPackaging extends Pivot
{
    use HasFactory;

    protected $table = 'product_packaging';

    public $incrementing = true;

    protected $guarded = [];

    protected $casts = [
        'quantity_per_unit' => 'decimal:3',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
