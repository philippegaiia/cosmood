<?php

namespace App\Models\Production;

use App\Models\Supply\Ingredient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'launch_date' => 'date',
            'net_weight' => 'decimal:3',
        ];
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    public function producedIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'produced_ingredient_id');
    }

    public function formulas(): BelongsToMany
    {
        return $this->belongsToMany(Formula::class, 'formula_product')
            ->using(FormulaProduct::class)
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function productFormulas(): HasMany
    {
        return $this->hasMany(FormulaProduct::class);
    }

    public function defaultFormula(): ?Formula
    {
        return $this->formulas()->wherePivot('is_default', true)->first();
    }

    public function packaging(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'product_packaging')
            ->using(ProductPackaging::class)
            ->withPivot('quantity_per_unit', 'sort')
            ->withTimestamps()
            ->where('ingredients.is_packaging', true);
    }

    public function productPackagingItems(): HasMany
    {
        return $this->hasMany(ProductPackaging::class);
    }

    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }

    public function setDefaultFormula(?int $formulaId): void
    {
        $this->formulas()->detach();

        if ($formulaId) {
            $this->formulas()->attach($formulaId, ['is_default' => true]);
        }
    }
}
