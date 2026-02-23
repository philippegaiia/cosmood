<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SizingMode: string implements HasColor, HasLabel
{
    case OilWeight = 'oil_weight';
    case FinalMass = 'final_mass';
    case Units = 'units';

    public function getLabel(): string
    {
        return match ($this) {
            self::OilWeight => 'Poids huiles (kg)',
            self::FinalMass => 'Masse finale (kg)',
            self::Units => 'Unités',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OilWeight => 'warning',
            self::FinalMass => 'info',
            self::Units => 'success',
        };
    }
}
