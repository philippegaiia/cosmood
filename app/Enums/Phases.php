<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Phases: string implements HasColor, HasLabel
{
    case Saponification = '10';
    case Lye = '20';
    case Additives = '30';
    case Packaging = '40';

    /**
     * Normalizes legacy phase aliases and enum instances to canonical string values.
     *
     * Handles three forms: enum instances (returns ->value), legacy DB aliases
     * ('saponified_oils', 'lye', 'additives'), and canonical strings (pass-through).
     */
    public static function normalize(self|string|null $phase): ?string
    {
        if ($phase === null) {
            return null;
        }

        if ($phase instanceof self) {
            return $phase->value;
        }

        return match ($phase) {
            'saponified_oils' => self::Saponification->value,
            'lye' => self::Lye->value,
            'additives' => self::Additives->value,
            default => $phase,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {

            self::Saponification => 'Huiles Saponifiées',
            self::Lye => 'Milieux Réactionnel',
            self::Additives => 'Additifs',
            self::Packaging => 'Packaging',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Saponification => 'gray',
            self::Lye => 'warning',
            self::Additives => 'info',
            self::Packaging => 'success',
        };
    }
}
