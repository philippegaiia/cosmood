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
     * @var array<string, float>
     */
    public array $summary = [];

    public string $search = '';

    public bool $shortageOnly = false;

    public function mount(): void
    {
        $this->reload();
    }

    public function reload(): void
    {
        $service = app(WaveProcurementService::class);

        $this->summary = $service->getActiveWavesPlanningSummary();
        $this->lines = $service->getActiveWavesPlanningList()
            ->map(fn (object $line): array => [
                'ingredient_name' => (string) ($line->ingredient_name ?? '-'),
                'required_remaining_quantity' => (float) ($line->required_remaining_quantity ?? 0),
                'ordered_quantity' => (float) ($line->ordered_quantity ?? 0),
                'received_quantity' => (float) ($line->received_quantity ?? 0),
                'covered_quantity' => (float) ($line->covered_quantity ?? 0),
                'firm_open_order_quantity' => (float) ($line->firm_open_order_quantity ?? 0),
                'draft_open_order_quantity' => (float) ($line->draft_open_order_quantity ?? 0),
                'to_order_quantity' => (float) ($line->to_order_quantity ?? 0),
                'committed_open_order_quantity' => (float) ($line->committed_open_order_quantity ?? 0),
                'priority_provisional_quantity' => (float) ($line->priority_provisional_quantity ?? 0),
                'to_secure_quantity' => (float) ($line->to_secure_quantity ?? 0),
                'coverage_warning' => (string) ($line->coverage_warning ?? ''),
                'stock_advisory' => (float) ($line->stock_advisory ?? 0),
                'open_order_quantity' => (float) ($line->open_order_quantity ?? 0),
                'shared_provisional_quantity' => (float) ($line->shared_provisional_quantity ?? 0),
                'advisory_shortage' => (float) ($line->advisory_shortage ?? 0),
                'earliest_need_date' => $line->earliest_need_date,
                'waves_count' => (int) ($line->waves_count ?? 0),
                'waves' => collect($line->waves)
                    ->map(fn (object $wave): array => [
                        'wave_name' => (string) ($wave->wave_name ?? '-'),
                        'wave_status' => (string) ($wave->wave_status ?? '-'),
                        'need_date' => $wave->need_date,
                        'required_remaining_quantity' => (float) ($wave->required_remaining_quantity ?? 0),
                        'ordered_quantity' => (float) ($wave->ordered_quantity ?? 0),
                        'received_quantity' => (float) ($wave->received_quantity ?? 0),
                        'covered_quantity' => (float) ($wave->covered_quantity ?? 0),
                        'to_order_quantity' => (float) ($wave->to_order_quantity ?? 0),
                        'committed_open_order_quantity' => (float) ($wave->committed_open_order_quantity ?? 0),
                        'priority_provisional_quantity' => (float) ($wave->priority_provisional_quantity ?? 0),
                        'to_secure_quantity' => (float) ($wave->to_secure_quantity ?? 0),
                        'commitment_excess_quantity' => (float) ($wave->commitment_excess_quantity ?? 0),
                        'coverage_warning' => (string) ($wave->coverage_warning ?? ''),
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
            ->when($this->shortageOnly, fn (Collection $lines): Collection => $lines->filter(fn (array $line): bool => $line['advisory_shortage'] > 0))
            ->values();

        return view('livewire.wave-procurement-overview-page', [
            'lines' => $filteredLines,
        ]);
    }
}
