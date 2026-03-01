<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AllocationStatus: string implements HasColor, HasLabel
{
    case Unassigned = 'unassigned';
    case Partial = 'partial';
    case Allocated = 'allocated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unassigned => 'Non alloué',
            self::Partial => 'Partiel',
            self::Allocated => 'Alloué',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unassigned => 'gray',
            self::Partial => 'warning',
            self::Allocated => 'success',
        };
    }
}
