<?php

namespace App\Livewire;

use App\Models\Production\Product;
use App\Services\Production\FlashSimulationService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SimulateurFlashPage extends Component
{
    /**
     * @var array<int, array{product_id: int|null, units: float|int|null}>
     */
    public array $lines = [];

    /**
     * @var array<int, string>
     */
    public array $productOptions = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $productLines = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $ingredientTotals = [];

    /**
     * @var array<int, string>
     */
    public array $warnings = [];

    /**
     * @var array<string, float|int>
     */
    public array $totals = [
        'products_count' => 0,
        'total_units' => 0,
        'total_batch_kg' => 0,
        'total_estimated_cost' => 0,
    ];

    public function mount(): void
    {
        $this->productOptions = Product::query()
            ->where('is_active', 1)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => $product->name.' ('.number_format((float) $product->net_weight, 0, ',', ' ').' g)',
            ])
            ->toArray();

        $this->lines = [
            [
                'product_id' => null,
                'units' => null,
            ],
        ];

        $this->recalculate();
    }

    public function updatedLines(): void
    {
        $this->recalculate();
    }

    public function addLine(): void
    {
        $this->lines[] = [
            'product_id' => null,
            'units' => null,
        ];
    }

    public function removeLine(int $index): void
    {
        if (! isset($this->lines[$index])) {
            return;
        }

        unset($this->lines[$index]);

        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->lines[] = [
                'product_id' => null,
                'units' => null,
            ];
        }

        $this->recalculate();
    }

    public function recalculate(): void
    {
        $result = app(FlashSimulationService::class)->simulate($this->lines);

        $this->productLines = $result['product_lines']->values()->all();
        $this->ingredientTotals = $result['ingredient_totals']->values()->all();
        $this->warnings = $result['warnings']->values()->all();
        $this->totals = $result['totals'];
    }

    public function render(): View
    {
        return view('livewire.simulateur-flash-page');
    }
}
