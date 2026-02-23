<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Packaging: string implements HasLabel
{
    case Bidon = '1';
    case Seau = '2';
    case Carton = '3';
    case Fût = '4';
    case Flacon = '5';
    case Unitaire = '6';
    case Vrac = '7';
    case Sac = '8';

    public function getLabel(): string
    // This is the method that will be called to get the label of the enum
    {
        return match ($this) {
            self::Bidon => 'Bidon',
            self::Seau => 'Seau',
            self::Carton => 'Carton',
            self::Fût => 'Fût',
            self::Flacon => 'Flacon',
            self::Unitaire => 'Unitaire',
            self::Vrac => 'Vrac',
            self::Sac => 'Sac',
        };
    }
}
