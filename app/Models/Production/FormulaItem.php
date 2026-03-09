<?php

namespace App\Models\Production;

use App\Enums\FormulaItemCalculationMode;
use App\Enums\Phases;
use App\Models\Supply\Ingredient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class FormulaItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'phase' => Phases::class,
        'calculation_mode' => FormulaItemCalculationMode::class,
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (FormulaItem $item): void {
            if (filled($item->percentage_of_oils) && (float) $item->percentage_of_oils < 0) {
                throw new InvalidArgumentException(__('Le pourcentage ne peut pas être négatif.'));
            }

            if ($item->ingredient_id) {
                $ingredient = Ingredient::find($item->ingredient_id);
                if ($ingredient && $ingredient->is_packaging) {
                    throw new InvalidArgumentException('Les ingrédients de packaging ne peuvent pas être ajoutés aux formules. Utilisez la section Packaging du produit.');
                }
            }
        });
    }

    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
