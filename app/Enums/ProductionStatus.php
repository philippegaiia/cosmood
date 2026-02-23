<?php

namespace App\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProductionStatus: string implements HasColor, HasLabel
{
    case Simulated = 'simulation';
    case Planned = 'planned';
    case Confirmed = 'confirmed';
    case Ongoing = 'ongoing';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    // This is the method that will be called to get the label of the enum
    {
        return match ($this) {

            self::Simulated => 'Simulation',
            self::Planned => 'Planifiée',
            self::Confirmed => 'Confirmée',
            self::Ongoing => 'En Cours',
            self::Finished => 'Terminée',
            self::Cancelled => 'Annulée',
        };
    }

    // This is ithe method that will be called to get the color of the enum
    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Simulated => Color::Stone,
            self::Planned => 'info',
            self::Confirmed => 'primary',
            self::Ongoing => 'warning',
            self::Finished => 'success',
            self::Cancelled => 'danger',
        };
    }
}
