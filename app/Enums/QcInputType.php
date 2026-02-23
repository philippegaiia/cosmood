<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum QcInputType: string implements HasLabel
{
    case Number = 'number';
    case Boolean = 'boolean';
    case Text = 'text';
    case Select = 'select';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Number => 'Numérique',
            self::Boolean => 'Oui / Non',
            self::Text => 'Texte',
            self::Select => 'Liste',
        };
    }
}
