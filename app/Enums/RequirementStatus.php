<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RequirementStatus: string implements HasColor, HasLabel
{
    case NotOrdered = 'not_ordered';
    case Ordered = 'ordered';
    case Confirmed = 'confirmed';
    case Received = 'received';
    case Allocated = 'allocated';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotOrdered => 'Non commandé',
            self::Ordered => 'Commandé',
            self::Confirmed => 'Confirmé',
            self::Received => 'Reçu',
            self::Allocated => 'Alloué',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotOrdered => 'gray',
            self::Ordered => 'info',
            self::Confirmed => 'primary',
            self::Received => 'warning',
            self::Allocated => 'success',
        };
    }
}
