<?php

namespace App\Models\Production;

use App\Models\Supply\Ingredient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPackaging extends Model
{
    use HasFactory;

    protected $table = 'product_packaging';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity_per_unit' => 'decimal:3',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
