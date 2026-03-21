<?php

namespace App\Models\Production;

use App\Enums\ProductionOutputKind;
use App\Models\Production\Concerns\BumpsParentProductionVersion;
use App\Models\Supply\Ingredient;
use Database\Factories\Production\ProductionOutputFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class ProductionOutput extends Model
{
    use BumpsParentProductionVersion;

    /** @use HasFactory<ProductionOutputFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (ProductionOutput $output): void {
            $output->normalizeAndValidate();
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => ProductionOutputKind::class,
            'quantity' => 'decimal:3',
        ];
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function getTargetLabel(): string
    {
        return match ($this->kind) {
            ProductionOutputKind::MainProduct => (string) ($this->product?->name ?? __('Produit principal')),
            ProductionOutputKind::ReworkMaterial => (string) ($this->ingredient?->name ?? __('Ingrédient rebatch')),
            ProductionOutputKind::Scrap => __('Perte / rebut'),
        };
    }

    private function normalizeAndValidate(): void
    {
        $kind = $this->kind instanceof ProductionOutputKind
            ? $this->kind
            : ProductionOutputKind::tryFrom((string) $this->kind);

        if (! $kind instanceof ProductionOutputKind) {
            throw new InvalidArgumentException(__('Le type de sortie est invalide.'));
        }

        $this->kind = $kind;
        $this->unit = strtolower(trim((string) $this->unit));

        if (! in_array($this->unit, ['u', 'kg'], true)) {
            throw new InvalidArgumentException(__('L\'unité de sortie doit être "u" ou "kg".'));
        }

        if ((float) $this->quantity < 0) {
            throw new InvalidArgumentException(__('La quantité de sortie ne peut pas être négative.'));
        }

        $production = $this->relationLoaded('production')
            ? $this->production
            : ($this->production_id ? Production::query()->with('product')->find($this->production_id) : null);

        $defaultMainUnit = $production?->getDefaultMainOutputUnit() ?? 'u';

        if ($this->production_id) {
            $duplicateExists = self::query()
                ->where('production_id', $this->production_id)
                ->where('kind', $kind->value)
                ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
                ->exists();

            if ($duplicateExists) {
                throw new InvalidArgumentException(__('Une sortie de type :kind existe déjà pour ce lot.', [
                    'kind' => $kind->getLabel(),
                ]));
            }
        }

        match ($kind) {
            ProductionOutputKind::MainProduct => $this->normalizeMainProductOutput($production, $defaultMainUnit),
            ProductionOutputKind::ReworkMaterial => $this->normalizeReworkMaterialOutput(),
            ProductionOutputKind::Scrap => $this->normalizeScrapOutput(),
        };
    }

    private function normalizeMainProductOutput(?Production $production, string $defaultMainUnit): void
    {
        if ($production && $production->product_id) {
            $this->product_id = $production->product_id;
        }

        if (! $this->product_id) {
            throw new InvalidArgumentException(__('La sortie principale doit être liée au produit fabriqué.'));
        }

        if ($production && (int) $this->product_id !== (int) $production->product_id) {
            throw new InvalidArgumentException(__('La sortie principale doit correspondre au produit de la fabrication.'));
        }

        $this->ingredient_id = null;

        if ($this->unit !== $defaultMainUnit) {
            throw new InvalidArgumentException(__('L\'unité de la sortie principale doit être :unit.', [
                'unit' => $defaultMainUnit,
            ]));
        }
    }

    private function normalizeReworkMaterialOutput(): void
    {
        $this->product_id = null;

        if (! $this->ingredient_id) {
            throw new InvalidArgumentException(__('La sortie rebatch doit être liée à un ingrédient fabriqué.'));
        }

        if ($this->unit !== 'kg') {
            throw new InvalidArgumentException(__('La sortie rebatch doit être exprimée en kg.'));
        }
    }

    private function normalizeScrapOutput(): void
    {
        $this->product_id = null;
        $this->ingredient_id = null;

        if ($this->unit !== 'kg') {
            throw new InvalidArgumentException(__('Le rebut doit être exprimé en kg.'));
        }
    }
}
