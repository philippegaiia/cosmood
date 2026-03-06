<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Enums\WaveStatus;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlashSimulationWavePlanner
{
    public function __construct(
        private readonly FlashSimulationService $flashSimulationService,
        private readonly PlanningBatchNumberService $planningBatchNumberService,
        private readonly WaveProductionPlanningService $waveProductionPlanningService,
    ) {}

    /**
     * Persists one production wave and its generated production batches from flash simulation lines.
     *
     * @param  array<int, array{product_id: int|string|null, desired_units?: int|float|string|null, units?: int|float|string|null, batch_size_preset_id?: int|string|null}>  $lines
     * @param  array{name?: string|null, start_date?: string|null, notes?: string|null, skip_weekends?: bool, skip_holidays?: bool, fallback_daily_capacity?: int}  $options
     */
    public function createWaveFromSimulation(array $lines, array $options = []): ProductionWave
    {
        $simulation = $this->flashSimulationService->simulate($lines);
        $productLines = $simulation['product_lines'] ?? collect();

        if (! $productLines instanceof Collection || $productLines->isEmpty()) {
            throw new \InvalidArgumentException(__('Aucune ligne valide à convertir en vague.'));
        }

        $startDate = Carbon::parse((string) ($options['start_date'] ?? now()->addWeeks(2)->toDateString()))->startOfDay();
        $rawWaveName = trim((string) ($options['name'] ?? ''));
        $waveName = $rawWaveName !== ''
            ? $rawWaveName
            : 'Vague '.now()->format('d/m/Y H:i');
        $notes = isset($options['notes']) && trim((string) $options['notes']) !== ''
            ? (string) $options['notes']
            : null;
        $skipWeekends = (bool) ($options['skip_weekends'] ?? true);
        $skipHolidays = (bool) ($options['skip_holidays'] ?? true);
        $fallbackDailyCapacity = max(1, (int) ($options['fallback_daily_capacity'] ?? 4));

        $products = Product::query()
            ->with([
                'productType:id,name,sizing_mode,default_batch_size,expected_units_output,expected_waste_kg,default_production_line_id',
                'formulas' => fn ($query) => $query
                    ->where('is_active', true)
                    ->withPivot('is_default')
                    ->orderByDesc('id'),
            ])
            ->whereIn('id', $productLines->pluck('product_id')->all())
            ->get()
            ->keyBy('id');

        $batchPlans = [];
        $batchPayloads = [];

        foreach ($productLines as $line) {
            /** @var Product|null $product */
            $product = $products->get((int) ($line['product_id'] ?? 0));

            if (! $product || ! $product->productType) {
                continue;
            }

            $formula = $this->resolveFormula($product);

            if (! $formula) {
                continue;
            }

            $batchesRequired = (int) ($line['batches_required'] ?? 0);
            $batchSizeKg = (float) ($line['batch_size_kg'] ?? 0);
            $unitsPerBatch = (float) ($line['units_per_batch'] ?? 0);

            if ($batchesRequired <= 0 || $batchSizeKg <= 0 || $unitsPerBatch <= 0) {
                continue;
            }

            for ($batchIndex = 0; $batchIndex < $batchesRequired; $batchIndex++) {
                $batchPlans[] = [
                    'production_line_id' => $product->productType->default_production_line_id,
                ];

                $batchPayloads[] = [
                    'product_id' => $product->id,
                    'formula_id' => $formula->id,
                    'product_type_id' => $product->productType->id,
                    'batch_size_preset_id' => isset($line['batch_size_preset_id']) ? (int) $line['batch_size_preset_id'] : null,
                    'production_line_id' => $product->productType->default_production_line_id,
                    'sizing_mode' => $product->productType->sizing_mode?->value,
                    'planned_quantity' => $batchSizeKg,
                    'expected_units' => (int) round($unitsPerBatch),
                    'expected_waste_kg' => $product->productType->expected_waste_kg,
                ];
            }
        }

        if ($batchPayloads === []) {
            throw new \InvalidArgumentException(__('Aucune production n\'a pu être générée à partir de la simulation.'));
        }

        $plannedDates = $this->waveProductionPlanningService->planBatchDates(
            batchPlans: $batchPlans,
            startDate: $startDate,
            skipWeekends: $skipWeekends,
            skipHolidays: $skipHolidays,
            fallbackDailyCapacity: $fallbackDailyCapacity,
        );

        return DB::transaction(function () use ($waveName, $notes, $startDate, $batchPayloads, $plannedDates): ProductionWave {
            $wave = ProductionWave::query()->create([
                'name' => $waveName,
                'slug' => $this->generateUniqueWaveSlug($waveName),
                'status' => WaveStatus::Draft,
                'planned_start_date' => $startDate->toDateString(),
                'planned_end_date' => null,
                'notes' => $notes,
            ]);

            $plannedEndDate = $startDate->toDateString();

            foreach ($batchPayloads as $index => $payload) {
                /** @var Carbon|null $plannedDate */
                $plannedDate = $plannedDates[$index] ?? null;

                if (! $plannedDate instanceof Carbon) {
                    continue;
                }

                $batchNumber = $this->planningBatchNumberService->generateNextReference();
                $plannedDateString = $plannedDate->toDateString();

                Production::query()->create([
                    'production_wave_id' => $wave->id,
                    'production_line_id' => $payload['production_line_id'],
                    'product_id' => $payload['product_id'],
                    'formula_id' => $payload['formula_id'],
                    'product_type_id' => $payload['product_type_id'],
                    'batch_size_preset_id' => $payload['batch_size_preset_id'],
                    'is_masterbatch' => false,
                    'sizing_mode' => $payload['sizing_mode'],
                    'planned_quantity' => $payload['planned_quantity'],
                    'expected_units' => $payload['expected_units'],
                    'expected_waste_kg' => $payload['expected_waste_kg'],
                    'slug' => $this->generateUniqueProductionSlug($batchNumber),
                    'batch_number' => $batchNumber,
                    'status' => ProductionStatus::Planned,
                    'production_date' => $plannedDateString,
                    'organic' => true,
                ]);

                $plannedEndDate = $plannedDateString;
            }

            $wave->update([
                'planned_end_date' => $plannedEndDate,
            ]);

            return $wave;
        });
    }

    private function resolveFormula(Product $product): ?Formula
    {
        $defaultFormula = $product->formulas
            ->first(fn (Formula $formula): bool => (bool) ($formula->pivot?->is_default ?? false));

        if ($defaultFormula) {
            return $defaultFormula;
        }

        return $product->formulas->first();
    }

    private function generateUniqueWaveSlug(string $waveName): string
    {
        $baseSlug = Str::slug($waveName);

        if ($baseSlug === '') {
            $baseSlug = 'wave';
        }

        $slug = $baseSlug;
        $index = 1;

        while (ProductionWave::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$index;
            $index++;
        }

        return $slug;
    }

    private function generateUniqueProductionSlug(string $batchNumber): string
    {
        $baseSlug = Str::slug($batchNumber);

        if ($baseSlug === '') {
            $baseSlug = 'batch';
        }

        $slug = $baseSlug;
        $index = 1;

        while (Production::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$index;
            $index++;
        }

        return $slug;
    }
}
