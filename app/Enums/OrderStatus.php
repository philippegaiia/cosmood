<?php

namespace App\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
{
    case Draft = '1';
    case Passed = '2';
    case Confirmed = '3';
    case Delivered = '4';
    case Checked = '5';
    case Cancelled = '6';

    public function getLabel(): string
    // This is the method that will be called to get the label of the enum
    {
        return match ($this) {

            self::Draft => 'Draft',
            self::Passed => 'Passée',
            self::Confirmed => 'Confirmée',
            self::Delivered => 'Livrée',
            self::Checked => 'Contrôlée',
            self::Cancelled => 'Annulée',
        };
    }

    // This is ithe method that will be called to get the color of the enum
    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => Color::Stone,
            self::Passed => 'info',
            self::Confirmed => 'primary',
            self::Delivered => 'warning',
            self::Checked => 'success',
            self::Cancelled => 'danger',
        };
    }
}
