<?php

namespace App\Models\Production;

use Database\Factories\Production\BrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
