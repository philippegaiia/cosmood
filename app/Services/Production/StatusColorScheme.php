<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use Filament\Support\Icons\Heroicon;

/**
 * Centralized color scheme for composite production and supply states.
 *
 * This service provides consistent visual representation of combined states
 * (e.g., production status + supply readiness) across the application.
 *
 * Color semantics used throughout:
 * - zinc/slate: Planning phases (draft, planned)
 * - blue/info: Committed/confirmed states
 * - amber/orange: In progress, pending, or ordered
 * - emerald/green/teal: Success, completed, received, allocated
 * - yellow: Partial or warning states
 * - red/rose: Critical, missing, cancelled, expired
 * - violet/indigo: Informational, checked, internal
 */
class StatusColorScheme
{
    /**
     * Get visual representation for a production's combined state.
     *
     * Combines production status with supply coverage to provide
     * actionable visual feedback in the UI.
     *
     * @return array{
     *     color: string,
     *     icon: Heroicon|null,
     *     label: string,
     *     description: string|null
     * }
     */
    public static function forProduction(Production $production): array
    {
        $status = $production->status;
        $supplyState = $production->getSupplyCoverageState();

        // Ready to start: Confirmed + all supplies received
        if ($status === ProductionStatus::Confirmed && $supplyState === 'received') {
            return [
                'color' => 'emerald',
                'icon' => Heroicon::Play,
                'label' => 'Prêt à lancer',
                'description' => 'Stock complet, prêt pour production',
            ];
        }

        // Missing supplies: Confirmed + missing items
        if ($status === ProductionStatus::Confirmed && $supplyState === 'missing') {
            return [
                'color' => 'rose',
                'icon' => Heroicon::ExclamationTriangle,
                'label' => 'Stock manquant',
                'description' => 'Ingrédients manquants pour le lancement',
            ];
        }

        // Supplies ordered: Confirmed + items ordered but not all received
        if ($status === ProductionStatus::Confirmed && $supplyState === 'ordered') {
            return [
                'color' => 'amber',
                'icon' => Heroicon::Clock,
                'label' => 'En approvisionnement',
                'description' => 'Commande en cours de livraison',
            ];
        }

        // Planned states
        if ($status === ProductionStatus::Planned) {
            return [
                'color' => 'slate',
                'icon' => null,
                'label' => $status->getLabel(),
                'description' => 'Planifié, en attente de confirmation',
            ];
        }

        // Active production
        if ($status === ProductionStatus::Ongoing) {
            return [
                'color' => 'orange',
                'icon' => Heroicon::Beaker,
                'label' => $status->getLabel(),
                'description' => 'Production en cours',
            ];
        }

        // Terminal states - use standard enum colors
        return [
            'color' => $status->getColor(),
            'icon' => match ($status) {
                ProductionStatus::Finished => Heroicon::CheckCircle,
                ProductionStatus::Cancelled => Heroicon::XCircle,
                default => null,
            },
            'label' => $status->getLabel(),
            'description' => null,
        ];
    }

    /**
     * Get color scheme for supply availability state.
     *
     * @param  float  $available  Available quantity
     * @param  float  $minimum  Minimum stock threshold (0 = no minimum)
     * @param  float  $allocated  Currently allocated quantity
     * @return array{
     *     color: string,
     *     icon: Heroicon,
     *     status: 'critical'|'warning'|'ok'
     * }
     */
    public static function forSupplyAvailability(
        float $available,
        float $minimum = 0,
        float $allocated = 0,
    ): array {
        // Critical: No stock available
        if ($available <= 0) {
            return [
                'color' => 'rose',
                'icon' => Heroicon::XCircle,
                'status' => 'critical',
            ];
        }

        // Warning: Below minimum threshold
        if ($minimum > 0 && $available <= $minimum) {
            return [
                'color' => 'amber',
                'icon' => Heroicon::ExclamationTriangle,
                'status' => 'warning',
            ];
        }

        // Warning: High allocation ratio (more than 80% allocated)
        $total = $available + $allocated;
        if ($total > 0 && ($allocated / $total) > 0.8) {
            return [
                'color' => 'yellow',
                'icon' => Heroicon::ChartPie,
                'status' => 'warning',
            ];
        }

        // OK: Sufficient stock
        return [
            'color' => 'emerald',
            'icon' => Heroicon::CheckCircle,
            'status' => 'ok',
        ];
    }

    /**
     * Get color for DLUO (expiry date) state.
     *
     * @return array{
     *     color: string,
     *     icon: Heroicon,
     *     label: string
     * }
     */
    public static function forExpiryDate(?\Carbon\Carbon $expiryDate): array
    {
        if ($expiryDate === null) {
            return [
                'color' => 'gray',
                'icon' => Heroicon::QuestionMarkCircle,
                'label' => 'Non définie',
            ];
        }

        $now = now();
        $daysUntilExpiry = $now->diffInDays($expiryDate, false);

        // Expired
        if ($daysUntilExpiry < 0) {
            return [
                'color' => 'rose',
                'icon' => Heroicon::XCircle,
                'label' => 'Expiré',
            ];
        }

        // Expires within 30 days
        if ($daysUntilExpiry <= 30) {
            return [
                'color' => 'orange',
                'icon' => Heroicon::ExclamationCircle,
                'label' => 'Expire bientôt',
            ];
        }

        // Expires within 90 days
        if ($daysUntilExpiry <= 90) {
            return [
                'color' => 'yellow',
                'icon' => Heroicon::Clock,
                'label' => 'Valide',
            ];
        }

        // Good
        return [
            'color' => 'emerald',
            'icon' => Heroicon::CheckCircle,
            'label' => 'Valide',
        ];
    }

    /**
     * Get color for supply coverage aggregated state.
     *
     * Used for the "Appro" column in productions table.
     *
     * @return array{
     *     color: string,
     *     icon: Heroicon|null,
     *     label: string
     * }
     */
    public static function forSupplyCoverage(string $coverageState): array
    {
        return match ($coverageState) {
            'received' => [
                'color' => 'success',
                'icon' => Heroicon::CheckCircle,
                'label' => 'Approvisionné',
            ],
            'ordered' => [
                'color' => 'warning',
                'icon' => Heroicon::Clock,
                'label' => 'Commandé',
            ],
            'missing' => [
                'color' => 'danger',
                'icon' => Heroicon::ExclamationTriangle,
                'label' => 'Manquant',
            ],
            default => [
                'color' => 'gray',
                'icon' => null,
                'label' => 'Inconnu',
            ],
        };
    }
}
