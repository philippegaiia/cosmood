<?php

namespace App\Livewire;

use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use App\Models\Production\BatchSizePreset;
use App\Models\Production\Product;
use App\Services\Production\FlashSimulationService;
use App\Services\Production\FlashSimulationWavePlanner;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class SimulateurFlashPage extends Component
{
    /**
     * @var array<int, array{line_key: string, product_id: int|null, desired_units: float|int|null, batch_size_preset_id: int|null}>
     */
    public array $lines = [];

    /**
     * @var array<int, string>
     */
    public array $productOptions = [];

    /**
     * @var array<int, array<int, string>>
     */
    public array $batchPresetOptionsByProduct = [];

    /**
     * @var array<int, int|null>
     */
    public array $defaultPresetIdByProduct = [];

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
     * @var array<int, array<string, mixed>>
     */
    public array $taskTotals = [];

    /**
     * @var array<string, float|int>
     */
    public array $totals = [
        'products_count' => 0,
        'total_units' => 0,
        'total_desired_units' => 0,
        'total_produced_units' => 0,
        'total_extra_units' => 0,
        'total_batches' => 0,
        'total_batch_kg' => 0,
        'total_estimated_cost' => 0,
        'total_duration_minutes' => 0,
    ];

    public string $waveName = '';

    public ?string $waveStartDate = null;

    public bool $plannerSkipWeekends = true;

    public bool $plannerSkipHolidays = true;

    public int $plannerFallbackDailyCapacity = 4;

    public ?string $waveNotes = null;

    public function mount(): void
    {
        $products = Product::query()
            ->with(['productType.batchSizePresets'])
            ->orderBy('name')
            ->get()

            ->each(function (Product $product): void {
                $productType = $product->productType;

                if (! $productType) {
                    $this->batchPresetOptionsByProduct[$product->id] = [];
                    $this->defaultPresetIdByProduct[$product->id] = null;

                    return;
                }

                $productType->loadMissing('batchSizePresets');

                $presetOptions = $productType->batchSizePresets
                    ->sortBy(fn (BatchSizePreset $preset): string => sprintf(
                        '%d-%s',
                        $preset->is_default ? 0 : 1,
                        strtolower($preset->name),
                    ))
                    ->mapWithKeys(fn (BatchSizePreset $preset): array => [
                        $preset->id => sprintf(
                            '%s - %s unites - %s kg',
                            $preset->name,
                            number_format((float) $preset->expected_units, 0, ',', ' '),
                            number_format((float) $preset->batch_size, 3, ',', ' '),
                        ),
                    ])
                    ->toArray();

                $defaultPresetId = $productType->batchSizePresets
                    ->first(fn (BatchSizePreset $preset): bool => (bool) $preset->is_default)?->id;

                $this->batchPresetOptionsByProduct[$product->id] = $presetOptions;
                $this->defaultPresetIdByProduct[$product->id] = $defaultPresetId;
            });

        $this->productOptions = $products
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => $product->name,
            ])
            ->toArray();

        $this->lines = [$this->makeLine()];

        $this->waveName = 'Vague '.now()->format('d/m/Y H:i');
        $this->waveStartDate = now()->addWeeks(2)->toDateString();

        $this->recalculate();
    }

    /**
     * @param  float|int|string|null  $value
     */
    public function updatedLines($value, string $path): void
    {
        [$index, $field] = explode('.', $path, 3) + [null, null];

        if ($field === 'product_id' && is_numeric($index)) {
            $lineIndex = (int) $index;
            $productId = (int) ($this->lines[$lineIndex]['product_id'] ?? 0);

            $this->lines[$lineIndex]['batch_size_preset_id'] = $productId > 0
                ? ($this->defaultPresetIdByProduct[$productId] ?? null)
                : null;
        }

        $this->recalculate();
    }

    public function addLine(): void
    {
        $this->lines[] = $this->makeLine();
    }

    public function removeLine(int $index): void
    {
        if (! isset($this->lines[$index])) {
            return;
        }

        unset($this->lines[$index]);

        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->lines[] = $this->makeLine();
        }

        $this->recalculate();
    }

    public function recalculate(): void
    {
        $result = app(FlashSimulationService::class)->simulate($this->lines);

        $this->productLines = $result['product_lines']->values()->all();
        $this->ingredientTotals = $result['ingredient_totals']->values()->all();
        $this->taskTotals = $result['task_totals']->values()->all();
        $this->warnings = $result['warnings']->values()->all();
        $this->totals = $result['totals'];
    }

    public function createWaveFromSimulation(): mixed
    {
        if (count($this->productLines) === 0 || (int) ($this->totals['total_batches'] ?? 0) <= 0) {
            Notification::make()
                ->title(__('Aucun batch à planifier'))
                ->body(__('Ajoutez au moins un produit avec une quantité supérieure à zéro.'))
                ->warning()
                ->send();

            return null;
        }

        $validated = $this->validate([
            'waveName' => ['required', 'string', 'max:255'],
            'waveStartDate' => ['required', 'date'],
            'plannerFallbackDailyCapacity' => ['required', 'integer', 'min:1', 'max:50'],
            'plannerSkipWeekends' => ['boolean'],
            'plannerSkipHolidays' => ['boolean'],
            'waveNotes' => ['nullable', 'string'],
        ]);

        try {
            $wave = app(FlashSimulationWavePlanner::class)->createWaveFromSimulation(
                lines: $this->lines,
                options: [
                    'name' => $validated['waveName'],
                    'start_date' => $validated['waveStartDate'],
                    'notes' => $validated['waveNotes'] ?? null,
                    'skip_weekends' => (bool) ($validated['plannerSkipWeekends'] ?? true),
                    'skip_holidays' => (bool) ($validated['plannerSkipHolidays'] ?? true),
                    'fallback_daily_capacity' => (int) ($validated['plannerFallbackDailyCapacity'] ?? 4),
                ],
            );

            Notification::make()
                ->title(__('Vague créée'))
                ->body(__('La vague :name a été générée avec :count batch(es).', [
                    'name' => $wave->name,
                    'count' => (string) $wave->productions()->count(),
                ]))
                ->success()
                ->send();

            return $this->redirect(ProductionWaveResource::getUrl('edit', ['record' => $wave]));
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('Impossible de créer la vague'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    public function getBatchPresetOptionsForLine(int $lineIndex): array
    {
        $productId = (int) ($this->lines[$lineIndex]['product_id'] ?? 0);

        if ($productId <= 0) {
            return [];
        }

        return $this->batchPresetOptionsByProduct[$productId] ?? [];
    }

    public function render(): View
    {
        return view('livewire.simulateur-flash-page');
    }

    /**
     * Format a duration in minutes into a human-readable hours/minutes string.
     */
    public function formatDurationMinutes(float|int $minutes): string
    {
        $roundedMinutes = max(0, (int) round((float) $minutes));
        $hours = intdiv($roundedMinutes, 60);
        $remainingMinutes = $roundedMinutes % 60;

        if ($hours > 0 && $remainingMinutes > 0) {
            return __(':hours h :minutes min', [
                'hours' => $hours,
                'minutes' => $remainingMinutes,
            ]);
        }

        if ($hours > 0) {
            return __(':hours h', ['hours' => $hours]);
        }

        return __(':minutes min', ['minutes' => $remainingMinutes]);
    }

    /**
     * Format a duration summary as rounded hours for dashboard cards.
     */
    public function formatRoundedHours(float|int $minutes): string
    {
        return __(':hours h', [
            'hours' => (int) round(((float) $minutes) / 60),
        ]);
    }

    /**
     * @return array{line_key: string, product_id: int|null, desired_units: float|int|null, batch_size_preset_id: int|null}
     */
    private function makeLine(): array
    {
        return [
            'line_key' => (string) Str::uuid(),
            'product_id' => null,
            'desired_units' => null,
            'batch_size_preset_id' => null,
        ];
    }
}
