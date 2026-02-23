<?php

namespace App\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IsInSuppliesStatus: string implements HasColor, HasLabel
{
    case Pending = '1';
    case Stock = '2';

    public function getLabel(): string
    // This is the method that will be called to get the label of the enum
    {
        return match ($this) {

            self::Pending => 'Attente',
            self::Stock => 'Inventaire',
        };
    }

    // This is ithe method that will be called to get the color of the enum
    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => Color::Slate,
            self::Stock => 'success',
        };
    }
}
