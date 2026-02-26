<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FormulaItemCalculationMode: string implements HasLabel
{
    case PercentOfOils = 'percent_of_oils';
    case QuantityPerUnit = 'qty_per_unit';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::PercentOfOils => '% d\'huiles',
            self::QuantityPerUnit => 'Qté / unité',
        };
    }
}
