<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\ProductionStatus;
use App\Enums\WaveStatus;
use App\Filament\Pages\PlanningBoard;
use App\Filament\Pages\ProductionDashboard;
use App\Filament\Pages\PurchasingDashboard;
use App\Filament\Pages\WaveProcurementOverview;
use App\Filament\Resources\Production\ProductionResource;
use App\Filament\Resources\Supply\SupplierOrderResource;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionWave;
use App\Models\Supply\SupplierOrder;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class UrgencesWidget extends Widget
{
    protected string $view = 'filament.widgets.urgences-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{
     *     sections: array<int, array{
     *         title: string,
     *         subtitle: string,
     *         tone: string,
     *         empty_state: string,
     *         action_label: string,
     *         action_url: string,
     *         items: Collection<int, array<string, string>>
     *     }>,
     *     total_items: int,
     *     headline_tone: string
     * }
     */
    protected function getViewData(): array
    {
        $sections = [
            [
                'title' => __('Blocages production'),
                'subtitle' => __('À débloquer avant lancement'),
                'tone' => 'danger',
                'empty_state' => __('Aucun blocage de production.'),
                'action_label' => __('Voir planning'),
                'action_url' => PlanningBoard::getUrl(),
                'items' => $this->getBlockedProductions(),
            ],
            [
                'title' => __('Achats à traiter'),
                'subtitle' => __('Relances ou réceptions à clarifier'),
                'tone' => 'danger',
                'empty_state' => __('Aucun achat en attente critique.'),
                'action_label' => __('Voir achats'),
                'action_url' => PurchasingDashboard::getUrl(),
                'items' => $this->getOverdueOrders(),
            ],
            [
                'title' => __('Tâches à rattraper'),
                'subtitle' => __('Travail en retard sur le planning'),
                'tone' => 'warning',
                'empty_state' => __('Aucune tâche en retard.'),
                'action_label' => __('Voir production'),
                'action_url' => ProductionDashboard::getUrl(),
                'items' => $this->getOverdueTasks(),
            ],
            [
                'title' => __('Vagues sous tension'),
                'subtitle' => __('Couverture achats à arbitrer'),
                'tone' => 'warning',
                'empty_state' => __('Aucune vague sous tension.'),
                'action_label' => __('Voir couverture'),
                'action_url' => WaveProcurementOverview::getUrl(),
                'items' => $this->getRiskyWaves(),
            ],
        ];

        $totalItems = collect($sections)->sum(fn (array $section): int => $section['items']->count());

        return [
            'sections' => $sections,
            'total_items' => $totalItems,
            'headline_tone' => collect($sections)
                ->contains(fn (array $section): bool => $section['tone'] === 'danger' && $section['items']->isNotEmpty())
                ? 'danger'
                : ($totalItems > 0 ? 'warning' : 'success'),
        ];
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    private function getBlockedProductions(): Collection
    {
        return Production::query()
            ->with(['product'])
            ->where('status', ProductionStatus::Confirmed)
            ->whereDate('production_date', '<=', today())
            ->whereHas('productionItems', function ($query): void {
                $query->whereDoesntHave('allocations');
            })
            ->get()
            ->map(fn (Production $production): array => [
                'label' => $this->buildContextLabel(
                    primary: $production->batch_number,
                    secondary: $production->product?->name,
                    primaryFallback: __('Production'),
                    secondaryFallback: __('Produit'),
                ),
                'meta' => __('Prévue le :date. Matières encore non affectées.', [
                    'date' => $production->production_date?->format('d/m/Y') ?? '-',
                ]),
                'url' => ProductionResource::getUrl('view', ['record' => $production]),
                'tone' => 'danger',
                'badge' => __('Bloqué'),
                'score' => 300 + $this->calculatePastDaysScore($production->production_date),
            ])
            ->sortByDesc('score')
            ->take(3)
            ->values();
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    private function getOverdueTasks(): Collection
    {
        return ProductionTask::query()
            ->with(['production.product'])
            ->whereDate('scheduled_date', '<', today())
            ->where('is_finished', false)
            ->whereNull('cancelled_at')
            ->get()
            ->map(fn (ProductionTask $task): array => [
                'label' => $this->buildContextLabel(
                    primary: $task->name,
                    secondary: $task->production?->product?->name,
                    primaryFallback: __('Tâche'),
                    secondaryFallback: __('Production'),
                ),
                'meta' => __('Prévue le :date. Toujours ouverte.', [
                    'date' => $task->scheduled_date?->format('d/m/Y') ?? '-',
                ]),
                'url' => $task->production_id
                    ? ProductionResource::getUrl('view', ['record' => $task->production_id])
                    : '#',
                'tone' => 'warning',
                'badge' => __('En retard'),
                'score' => 200 + $this->calculatePastDaysScore($task->scheduled_date),
            ])
            ->sortByDesc('score')
            ->take(3)
            ->values();
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    private function getOverdueOrders(): Collection
    {
        return SupplierOrder::query()
            ->with('supplier')
            ->whereDate('delivery_date', '<', today())
            ->whereIn('order_status', [
                OrderStatus::Passed,
                OrderStatus::Confirmed,
                OrderStatus::Delivered,
            ])
            ->get()
            ->map(function (SupplierOrder $order): array {
                $isReceptionPending = $order->order_status === OrderStatus::Delivered;

                return [
                    'label' => $this->buildContextLabel(
                        primary: $order->order_ref,
                        secondary: $order->supplier?->name,
                        primaryFallback: __('Commande'),
                        secondaryFallback: __('Fournisseur'),
                    ),
                    'meta' => $isReceptionPending
                        ? __('Livrée. Réception encore non contrôlée.')
                        : __('Livraison attendue le :date.', [
                            'date' => $order->delivery_date?->format('d/m/Y') ?? '-',
                        ]),
                    'url' => SupplierOrderResource::getUrl('edit', ['record' => $order]),
                    'tone' => $isReceptionPending ? 'warning' : 'danger',
                    'badge' => $isReceptionPending ? __('À contrôler') : __('À relancer'),
                    'score' => $this->resolveOrderSeverityBaseScore($order->order_status) + $this->calculatePastDaysScore($order->delivery_date),
                ];
            })
            ->sortByDesc('score')
            ->take(3)
            ->values();
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    private function getRiskyWaves(): Collection
    {
        return ProductionWave::query()
            ->withCount('productions')
            ->whereIn('status', [WaveStatus::Approved, WaveStatus::InProgress])
            ->get()
            ->filter(fn (ProductionWave $wave): bool => $wave->getCoverageSignalLabel() !== __('Prête'))
            ->map(fn (ProductionWave $wave): array => [
                'label' => $wave->name,
                'meta' => __('Couverture achats :signal.', [
                    'signal' => $wave->getCoverageSignalLabel(),
                ]),
                'url' => WaveProcurementOverview::getUrl(),
                'tone' => $wave->getCoverageSignalColor() === 'danger' ? 'danger' : 'warning',
                'badge' => $wave->getCoverageSignalColor() === 'danger'
                    ? __('Risque fort')
                    : __('À surveiller'),
                'score' => $this->resolveWaveSeverityBaseScore($wave) + $this->resolveWaveStartUrgencyScore($wave),
            ])
            ->sortByDesc('score')
            ->take(3)
            ->values();
    }

    private function calculatePastDaysScore(mixed $date): int
    {
        if ($date === null) {
            return 0;
        }

        return max(0, (int) $date->startOfDay()->diffInDays(today(), false) * 10);
    }

    private function resolveOrderSeverityBaseScore(OrderStatus $status): int
    {
        return match ($status) {
            OrderStatus::Confirmed => 240,
            OrderStatus::Passed => 230,
            OrderStatus::Delivered => 180,
            default => 100,
        };
    }

    private function resolveWaveSeverityBaseScore(ProductionWave $wave): int
    {
        return $wave->getCoverageSignalColor() === 'danger' ? 180 : 120;
    }

    private function resolveWaveStartUrgencyScore(ProductionWave $wave): int
    {
        if (! $wave->planned_start_date) {
            return 0;
        }

        if ($wave->planned_start_date->lte(today())) {
            return 40;
        }

        if ($wave->planned_start_date->lte(today()->copy()->addDays(3))) {
            return 20;
        }

        return 0;
    }

    protected function buildContextLabel(mixed $primary, mixed $secondary, mixed $primaryFallback, mixed $secondaryFallback): string
    {
        $primaryText = $this->stringifyValue($primary, $primaryFallback);
        $secondaryText = $this->stringifyValue($secondary, $secondaryFallback);

        return trim($primaryText.' - '.$secondaryText);
    }

    protected function stringifyValue(mixed $value, mixed $fallback): string
    {
        $fallbackText = $this->normalizeFallback($fallback);

        if ($value === null) {
            return $fallbackText;
        }

        if (is_scalar($value)) {
            $text = trim((string) $value);

            return $text !== '' ? $text : $fallbackText;
        }

        if (is_array($value)) {
            $parts = collect($value)
                ->flatten()
                ->filter(fn (mixed $part): bool => is_scalar($part) && trim((string) $part) !== '')
                ->map(fn (mixed $part): string => trim((string) $part))
                ->values();

            if ($parts->isNotEmpty()) {
                return $parts->join(' / ');
            }
        }

        if ($value instanceof \Stringable) {
            $text = trim((string) $value);

            return $text !== '' ? $text : $fallbackText;
        }

        return $fallbackText;
    }

    protected function normalizeFallback(mixed $fallback): string
    {
        if (is_scalar($fallback)) {
            $text = trim((string) $fallback);

            return $text !== '' ? $text : '-';
        }

        if (is_array($fallback)) {
            $parts = collect($fallback)
                ->flatten()
                ->filter(fn (mixed $part): bool => is_scalar($part) && trim((string) $part) !== '')
                ->map(fn (mixed $part): string => trim((string) $part))
                ->values();

            if ($parts->isNotEmpty()) {
                return $parts->join(' / ');
            }
        }

        if ($fallback instanceof \Stringable) {
            $text = trim((string) $fallback);

            return $text !== '' ? $text : '-';
        }

        return '-';
    }
}
