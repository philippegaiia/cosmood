<?php

namespace App\Models\Production;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Formula extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_soap' => 'boolean',
            'date_of_creation' => 'date',
        ];
    }

    public function formulaItems(): HasMany
    {
        return $this->hasMany(FormulaItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withDefault();
    }

    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }

    public function isMasterbatchFormula(): bool
    {
        return $this->replaces_phase !== null;
    }
}
