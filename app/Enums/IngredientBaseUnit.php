<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum IngredientBaseUnit: string implements HasLabel
{
    case Kg = 'kg';
    case Unit = 'u';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Kg => 'Kilogramme (kg)',
            self::Unit => 'Unitaire (u)',
        };
    }
}
