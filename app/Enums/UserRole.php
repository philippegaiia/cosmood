<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case User = 'utilisateur';
    case Manager = 'manager';
    case Production = 'production';
    case Purchase = 'achat';

    public function getLabel(): string
    // This is the method that will be called to get the label of the enum
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::User => 'Utilisateur',
            self::Manager => 'Manager',
            self::Production => 'Production',
            self::Purchase => 'Achat',
        };
    }
}
