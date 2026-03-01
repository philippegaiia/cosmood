<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Ingredient stock status for consolidated inventory view.
 *
 * Color semantics:
 * - success (green): Disponible - stock available and above minimum
 * - warning (yellow): Faible - stock available but below minimum threshold
 * - danger (red): Rupture - no stock available
 */
enum IngredientStockStatus: string implements HasColor, HasLabel
{
    case Disponible = 'disponible';
    case Faible = 'faible';
    case Rupture = 'rupture';

    public function getLabel(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Faible => 'Faible',
            self::Rupture => 'Rupture',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Disponible => 'success',
            self::Faible => 'warning',
            self::Rupture => 'danger',
        };
    }

    /**
     * Determine status based on available stock and minimum threshold.
     */
    public static function fromStock(float $available, ?float $minStock): self
    {
        if ($available <= 0) {
            return self::Rupture;
        }

        if ($minStock !== null && $minStock > 0 && $available <= $minStock) {
            return self::Faible;
        }

        return self::Disponible;
    }
}
