<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProductionOutputKind: string implements HasColor, HasLabel
{
    case MainProduct = 'main_product';
    case ReworkMaterial = 'rework_material';
    case Scrap = 'scrap';

    public function getLabel(): string
    {
        return match ($this) {
            self::MainProduct => __('Sortie principale'),
            self::ReworkMaterial => __('Matière de rebatch'),
            self::Scrap => __('Rebut'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::MainProduct => 'success',
            self::ReworkMaterial => 'warning',
            self::Scrap => 'danger',
        };
    }
}
