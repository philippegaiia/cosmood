<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Wave requirement fulfillment statuses.
 *
 * Color semantics (aligned with ProcurementStatus for consistency):
 * - danger: NotOrdered (immediate action required)
 * - warning: Ordered (in progress, awaiting delivery)
 * - primary: Confirmed (supplier commitment received)
 * - info: Received (physically available)
 * - success: Allocated (reserved to specific production)
 */
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
            self::NotOrdered => 'danger',
            self::Ordered => 'warning',
            self::Confirmed => 'primary',
            self::Received => 'info',
            self::Allocated => 'success',
        };
    }
}
