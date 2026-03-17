<?php

namespace App\Livewire;

use App\Services\Production\WaveProcurementService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class WaveProcurementOverviewPage extends Component
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $lines = [];

    /**
     * @var array<string, int|string>
     */
    public array $summary = [];

    public string $search = '';

    public bool $actionOnly = false;

    public function mount(): void
    {
        $this->reload();
    }

    public function reload(): void
    {
        $service = app(WaveProcurementService::class);

        $this->summary = $service->getOperationalPlanningSummary();
        $this->lines = $service->getOperationalPlanningList()
            ->map(fn (object $line): array => [
                'ingredient_name' => (string) ($line->ingredient_name ?? '-'),
                'display_unit' => (string) ($line->display_unit ?? 'kg'),
                'total_requirement' => (float) ($line->total_wave_requirement ?? 0),
                'remaining_requirement' => (float) ($line->remaining_requirement ?? 0),
                'available_stock' => (float) ($line->available_stock ?? 0),
                'wave_ordered_quantity' => (float) ($line->wave_ordered_quantity ?? 0),
                'wave_open_order_quantity' => (float) ($line->wave_open_order_quantity ?? 0),
                'wave_received_quantity' => (float) ($line->wave_received_quantity ?? 0),
                'open_orders_not_committed' => (float) ($line->open_orders_not_committed ?? 0),
                'remaining_to_secure' => (float) ($line->remaining_to_secure ?? 0),
                'remaining_to_order' => (float) ($line->remaining_to_order ?? 0),
                'earliest_need_date' => $line->earliest_need_date,
                'contexts_count' => (int) ($line->contexts_count ?? 0),
                'signal' => $this->resolveSignal(
                    remainingRequirement: (float) ($line->remaining_requirement ?? 0),
                    stockCoverage: (float) ($line->available_stock ?? 0),
                    waveOpenOrderQuantity: (float) ($line->wave_open_order_quantity ?? 0),
                    remainingToSecure: (float) ($line->remaining_to_secure ?? 0),
                    remainingToOrder: (float) ($line->remaining_to_order ?? 0),
                ),
                'contexts' => collect($line->contexts)
                    ->map(fn (object $context): array => [
                        'context_type' => (string) ($context->context_type ?? 'production'),
                        'context_type_label' => (string) (($context->context_type ?? 'production') === 'wave'
                            ? __('Vague')
                            : __('Lot isolé')),
                        'context_label' => (string) ($context->context_label ?? '-'),
                        'context_status' => (string) ($context->context_status ?? '-'),
                        'need_date' => $context->need_date,
                        'display_unit' => (string) ($context->display_unit ?? 'kg'),
                        'remaining_requirement' => (float) ($context->remaining_requirement ?? 0),
                        'wave_ordered_quantity' => (float) ($context->wave_ordered_quantity ?? 0),
                        'wave_open_order_quantity' => (float) ($context->wave_open_order_quantity ?? 0),
                        'wave_received_quantity' => (float) ($context->wave_received_quantity ?? 0),
                        'stock_priority_quantity' => (float) ($context->stock_priority_quantity ?? 0),
                        'open_orders_priority_quantity' => (float) ($context->open_orders_priority_quantity ?? 0),
                        'remaining_to_secure' => (float) ($context->remaining_to_secure ?? 0),
                        'remaining_to_order' => (float) ($context->remaining_to_order ?? 0),
                        'signal' => $this->resolveSignal(
                            remainingRequirement: (float) ($context->remaining_requirement ?? 0),
                            stockCoverage: (float) ($context->stock_priority_quantity ?? 0),
                            waveOpenOrderQuantity: (float) ($context->wave_open_order_quantity ?? 0),
                            remainingToSecure: (float) ($context->remaining_to_secure ?? 0),
                            remainingToOrder: (float) ($context->remaining_to_order ?? 0),
                        ),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    public function render(): View
    {
        $filteredLines = collect($this->lines)
            ->when($this->search !== '', function (Collection $lines): Collection {
                $needle = mb_strtolower($this->search);

                return $lines->filter(fn (array $line): bool => str_contains(mb_strtolower($line['ingredient_name']), $needle));
            })
            ->when($this->actionOnly, fn (Collection $lines): Collection => $lines->filter(fn (array $line): bool => $line['signal']['key'] !== 'ok'))
            ->values();

        return view('livewire.wave-procurement-overview-page', [
            'lines' => $filteredLines,
        ]);
    }

    /**
     * @return array{key: string, label: string}
     */
    private function resolveSignal(float $remainingRequirement, float $stockCoverage, float $waveOpenOrderQuantity, float $remainingToSecure, float $remainingToOrder): array
    {
        if ($remainingToOrder > 0) {
            return [
                'key' => 'order',
                'label' => __('À commander'),
            ];
        }

        if ($remainingToSecure > 0) {
            return [
                'key' => 'commit',
                'label' => __('À engager'),
            ];
        }

        if ($remainingRequirement > 0 && $stockCoverage > 0) {
            return [
                'key' => 'allocate',
                'label' => __('À affecter stock'),
            ];
        }

        if ($remainingRequirement > 0 && $waveOpenOrderQuantity > 0) {
            return [
                'key' => 'waiting',
                'label' => __('En attente réception'),
            ];
        }

        return [
            'key' => 'ok',
            'label' => __('OK'),
        ];
    }
}
