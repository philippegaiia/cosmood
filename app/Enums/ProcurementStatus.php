<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProcurementStatus: string implements HasColor, HasLabel
{
    case NotOrdered = 'not_ordered';
    case Ordered = 'ordered';
    case Confirmed = 'confirmed';
    case Received = 'received';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotOrdered => 'Non commandé',
            self::Ordered => 'Commandé',
            self::Confirmed => 'Confirmé',
            self::Received => 'Reçu',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotOrdered => 'danger',
            self::Ordered => 'warning',
            self::Confirmed => 'info',
            self::Received => 'success',
        };
    }
}
