<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Phases: string implements HasColor, HasLabel
{
    case Saponification = '10';
    case Lye = '20';
    case Additives = '30';
    case Aqueous = '50';
    case Oil = '60';
    case PhaseA = '70';
    case PhaseB = '80';
    case PhaseC = '90';
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

    public static function isPackaging(self|string|null $phase): bool
    {
        return self::normalize($phase) === self::Packaging->value;
    }

    public static function labelFor(self|string|null $phase, string $fallback = '-'): string
    {
        $normalizedPhase = self::normalize($phase);

        if ($normalizedPhase === null) {
            return $fallback;
        }

        return self::tryFrom($normalizedPhase)?->getLabel() ?? $normalizedPhase;
    }

    public static function defaultForFormula(bool $isSoap): self
    {
        return $isSoap ? self::Saponification : self::PhaseA;
    }

    public static function orderSql(string $column): string
    {
        $cases = collect(self::cases())
            ->values()
            ->map(fn (self $phase, int $index): string => sprintf(
                "WHEN %s = '%s' THEN %d",
                $column,
                $phase->value,
                $index + 1,
            ))
            ->implode(' ');

        return sprintf('CASE %s ELSE 999 END', $cases);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Saponification => __('Huiles Saponifiées'),
            self::Lye => __('Milieux Réactionnel'),
            self::Additives => __('Additifs'),
            self::Aqueous => __('Phase aqueuse'),
            self::Oil => __('Phase huileuse'),
            self::PhaseA => __('Phase A'),
            self::PhaseB => __('Phase B'),
            self::PhaseC => __('Phase C'),
            self::Packaging => __('Packaging'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Saponification => 'gray',
            self::Lye => 'warning',
            self::Additives => 'info',
            self::Aqueous => 'primary',
            self::Oil => 'warning',
            self::PhaseA => 'info',
            self::PhaseB => 'primary',
            self::PhaseC => 'gray',
            self::Packaging => 'success',
        };
    }
}
