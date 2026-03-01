<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Supplier order lifecycle statuses.
 *
 * Color semantics:
 * - gray: Draft (not yet actionable, planning phase)
 * - info: Passed (order sent to supplier, awaiting response)
 * - primary: Confirmed (supplier confirmed, commitment made)
 * - warning: Delivered (physically arrived, pending verification)
 * - success: Checked (verified and accepted into inventory)
 * - danger: Cancelled (order aborted, attention needed)
 */
enum OrderStatus: string implements HasColor, HasLabel
{
    case Draft = '1';
    case Passed = '2';
    case Confirmed = '3';
    case Delivered = '4';
    case Checked = '5';
    case Cancelled = '6';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Passed => 'Passée',
            self::Confirmed => 'Confirmée',
            self::Delivered => 'Livrée',
            self::Checked => 'Contrôlée',
            self::Cancelled => 'Annulée',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Passed => 'info',
            self::Confirmed => 'primary',
            self::Delivered => 'warning',
            self::Checked => 'success',
            self::Cancelled => 'danger',
        };
    }
}
