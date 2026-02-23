<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum QcResult: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Pass = 'pass';
    case Fail = 'fail';
    case NotApplicable = 'na';

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Pass => 'success',
            self::Fail => 'danger',
            self::NotApplicable => 'warning',
        };
    }

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Pass => 'Conforme',
            self::Fail => 'Non conforme',
            self::NotApplicable => 'N/A',
        };
    }
}
