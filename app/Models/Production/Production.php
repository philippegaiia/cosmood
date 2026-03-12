<?php

namespace App\Models\Production;

use App\Enums\ProcurementStatus;
use App\Enums\ProductionOutputKind;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Enums\WaveStatus;
use App\Filament\Resources\Production\ProductionResource;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use Carbon\Carbon;
use Guava\Calendar\Contracts\Eventable;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Production extends Model implements Eventable
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (Production $production): void {
            if (filled($production->planned_quantity) && (float) $production->planned_quantity < 0) {
                throw new InvalidArgumentException(__('La quantité planifiée ne peut pas être négative.'));
            }

            if (filled($production->expected_units) && (float) $production->expected_units < 0) {
                throw new InvalidArgumentException(__('Les unités attendues ne peuvent pas être négatives.'));
            }

            if ($production->isDirty('production_wave_id') && $production->production_wave_id !== null) {
                self::assertWaveIsAssignable($production);
            }

            if (self::shouldValidateAllowedProductionLineAssignment($production)) {
                self::assertAllowedProductionLineAssignment($production);
            }

            if (self::shouldValidateLineDailyCapacity($production)) {
                self::assertLineDailyCapacity($production);
            }

            if ($production->isDirty('ready_date') && filled($production->ready_date)) {
                return;
            }

            if (! $production->production_date) {
                return;
            }

            $shouldRefreshReadyDate =
                ! filled($production->ready_date)
                || $production->isDirty('production_date')
                || $production->isDirty('product_type_id');

            if (! $shouldRefreshReadyDate) {
                return;
            }

            $production->ready_date = self::estimateReadyDate(
                $production->production_date,
                $production->resolveProductTypeSlug(),
                $production->resolveProductTypeName(),
            )?->toDateString();
        });

        static::updating(function (Production $production): void {
            if ($production->isDirty('masterbatch_lot_id') && $production->masterbatch_lot_id !== null) {
                self::assertMasterbatchLotIsFinished($production);
            }

            if (! $production->isDirty('status')) {
                return;
            }

            $fromRaw = $production->getRawOriginal('status');
            $toRaw = $production->status;

            $from = ProductionStatus::tryFrom((string) $fromRaw);
            $to = $toRaw instanceof ProductionStatus ? $toRaw : ProductionStatus::tryFrom((string) $toRaw);

            if (! $from instanceof ProductionStatus || ! $to instanceof ProductionStatus) {
                return;
            }

            if (! self::canTransition($from, $to)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid production status transition from %s to %s.',
                    $from->value,
                    $to->value,
                ));
            }

            if ($from !== ProductionStatus::Ongoing && $to === ProductionStatus::Ongoing) {
                self::assertItemsAllocatedBeforeOngoing($production);
            }

            if ($from !== ProductionStatus::Finished && $to === ProductionStatus::Finished) {
                self::assertFinishRequirements($production);
            }
        });

        static::deleting(function (Production $production): void {
            $deletionBlocker = $production->getDeletionBlockerMessage();

            if ($deletionBlocker !== null) {
                throw new InvalidArgumentException($deletionBlocker);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'organic' => 'boolean',
            'is_masterbatch' => 'boolean',
            'status' => ProductionStatus::class,
            'sizing_mode' => SizingMode::class,
            'planned_quantity' => 'decimal:3',
            'expected_waste_kg' => 'decimal:3',
            'production_date' => 'date',
            'ready_date' => 'date',
            'permanent_batch_number' => 'string',
        ];
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(ProductionWave::class, 'production_wave_id');
    }

    public function productionLine(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    public function batchSizePreset(): BelongsTo
    {
        return $this->belongsTo(BatchSizePreset::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Production::class, 'parent_id');
    }

    public function masterbatchLot(): BelongsTo
    {
        return $this->belongsTo(Production::class, 'masterbatch_lot_id');
    }

    public function producedIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'produced_ingredient_id');
    }

    public function usedInProductions(): HasMany
    {
        return $this->hasMany(Production::class, 'masterbatch_lot_id');
    }

    public function producedSupply(): HasOne
    {
        return $this->hasOne(Supply::class, 'source_production_id');
    }

    public function productionItems(): HasMany
    {
        return $this->hasMany(ProductionItem::class);
    }

    public function productionTasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class);
    }

    public function productionOutputs(): HasMany
    {
        return $this->hasMany(ProductionOutput::class);
    }

    public function productionQcChecks(): HasMany
    {
        return $this->hasMany(ProductionQcCheck::class);
    }

    public function isOrphan(): bool
    {
        return $this->production_wave_id === null;
    }

    public function isMasterbatch(): bool
    {
        return $this->replaces_phase !== null;
    }

    public function usesMasterbatch(): bool
    {
        return $this->masterbatch_lot_id !== null;
    }

    public function getLotIdentifier(): string
    {
        return $this->permanent_batch_number ?: $this->batch_number;
    }

    public function getLotDisplayLabel(): string
    {
        if (! filled($this->permanent_batch_number)) {
            return (string) $this->batch_number;
        }

        return $this->permanent_batch_number.' (plan '.$this->batch_number.')';
    }

    public function hasDeclaredOutputs(): bool
    {
        return $this->productionOutputs()->exists();
    }

    public function getMainOutput(): ?ProductionOutput
    {
        if ($this->relationLoaded('productionOutputs')) {
            return $this->productionOutputs->first(fn (ProductionOutput $output): bool => $output->kind === ProductionOutputKind::MainProduct);
        }

        return $this->productionOutputs()
            ->where('kind', ProductionOutputKind::MainProduct->value)
            ->first();
    }

    public function getDefaultMainOutputUnit(): string
    {
        return $this->resolveProducedIngredientIdForOutput() !== null ? 'kg' : 'u';
    }

    public function getDefaultMainOutputQuantity(): float
    {
        if ($this->getDefaultMainOutputUnit() === 'kg') {
            return (float) ($this->planned_quantity ?? 0);
        }

        return (float) ($this->actual_units ?? $this->expected_units ?? 0);
    }

    public function getStockCreatingOutput(): ?ProductionOutput
    {
        $outputs = $this->relationLoaded('productionOutputs')
            ? $this->productionOutputs
            : $this->productionOutputs()->get();

        if ($this->resolveProducedIngredientIdForOutput() !== null) {
            return $outputs->first(fn (ProductionOutput $output): bool => $output->kind === ProductionOutputKind::MainProduct);
        }

        return $outputs->first(fn (ProductionOutput $output): bool => $output->kind === ProductionOutputKind::ReworkMaterial);
    }

    public function getSupplyCoverageState(): string
    {
        $this->loadMissing('productionItems.allocations');

        $items = $this->productionItems;

        if ($items->isEmpty()) {
            return 'missing';
        }

        $replacedPhase = $this->masterbatch_lot_id
            ? self::resolveMasterbatchReplacedPhase($this)
            : null;

        $hasOrdered = false;

        foreach ($items as $item) {
            if ($replacedPhase !== null && $item->phase === $replacedPhase) {
                continue;
            }

            if ($item->isCoveredByProcurementSignal()) {
                if (! $item->isFullyAllocated() && in_array($item->procurement_status, [ProcurementStatus::Ordered, ProcurementStatus::Confirmed], true)) {
                    $hasOrdered = true;
                }

                continue;
            }

            if ($item->procurement_status === ProcurementStatus::NotOrdered) {
                return 'missing';
            }

            $hasOrdered = true;
        }

        return $hasOrdered ? 'ordered' : 'received';
    }

    public function getSupplyCoverageLabel(): string
    {
        return match ($this->getSupplyCoverageState()) {
            'received' => 'Approvisionné',
            'ordered' => 'Commandé',
            default => 'Manquant',
        };
    }

    public function getSupplyCoverageColor(): string
    {
        return match ($this->getSupplyCoverageState()) {
            'received' => 'success',
            'ordered' => 'warning',
            default => 'danger',
        };
    }

    public function canBeDeleted(): bool
    {
        return $this->getDeletionBlockerMessage() === null;
    }

    public function getDeletionBlockerMessage(): ?string
    {
        if ($this->producedSupply()->exists()) {
            return __('Impossible de supprimer une production ayant créé un stock fabriqué.');
        }

        $hasConsumedAllocations = $this->productionItems()
            ->whereHas('allocations', fn ($query) => $query->where('status', 'consumed'))
            ->exists();

        if ($hasConsumedAllocations) {
            return __('Impossible de supprimer une production avec des consommations de stock.');
        }

        $status = $this->status instanceof ProductionStatus
            ? $this->status
            : ProductionStatus::tryFrom((string) $this->status);

        if (! in_array($status, [ProductionStatus::Planned, ProductionStatus::Confirmed], true)) {
            return __('Seules les productions planifiées ou confirmées peuvent être supprimées définitivement.');
        }

        return null;
    }

    public function hasManualOrderMarkedItems(): bool
    {
        return $this->getManualOrderMarkedItemsCount() > 0;
    }

    public function getManualOrderMarkedItemsCount(): int
    {
        $this->loadMissing('productionItems');

        $replacedPhase = $this->masterbatch_lot_id
            ? self::resolveMasterbatchReplacedPhase($this)
            : null;

        return $this->productionItems
            ->when($replacedPhase !== null, fn ($items) => $items->where('phase', '!=', $replacedPhase))
            ->where('is_order_marked', true)
            ->count();
    }

    /**
     * @return array<string, array<int, ProductionStatus>>
     */
    public static function transitionMap(): array
    {
        return [
            ProductionStatus::Planned->value => [
                ProductionStatus::Confirmed,
            ],
            ProductionStatus::Confirmed->value => [
                ProductionStatus::Ongoing,
            ],
            ProductionStatus::Ongoing->value => [
                ProductionStatus::Finished,
            ],
            ProductionStatus::Finished->value => [],
            ProductionStatus::Cancelled->value => [],
        ];
    }

    public static function canTransition(ProductionStatus $from, ProductionStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::transitionMap()[$from->value] ?? [], true);
    }

    /**
     * @return array<int, ProductionStatus>
     */
    public static function allowedTransitionsFor(ProductionStatus $from): array
    {
        return array_merge([$from], self::transitionMap()[$from->value] ?? []);
    }

    public function resolveProductTypeSlug(): string
    {
        if ($this->relationLoaded('productType') && $this->productType) {
            return strtolower((string) ($this->productType->slug ?? ''));
        }

        if (! $this->product_type_id) {
            return '';
        }

        return strtolower((string) (ProductType::query()->find($this->product_type_id)?->slug ?? ''));
    }

    public function resolveProductTypeName(): string
    {
        if ($this->relationLoaded('productType') && $this->productType) {
            return strtolower((string) ($this->productType->name ?? ''));
        }

        if (! $this->product_type_id) {
            return '';
        }

        return strtolower((string) (ProductType::query()->find($this->product_type_id)?->name ?? ''));
    }

    public static function estimateReadyDate(Carbon|string $productionDate, string $productTypeSlug = '', string $productTypeName = ''): Carbon
    {
        $baseDate = $productionDate instanceof Carbon
            ? $productionDate->copy()->startOfDay()
            : Carbon::parse($productionDate)->startOfDay();

        $slug = strtolower($productTypeSlug);
        $name = strtolower($productTypeName);

        $isSoap = str_contains($slug, 'soap') || str_contains($slug, 'savon') || str_contains($name, 'soap') || str_contains($name, 'savon');

        if ($isSoap) {
            return $baseDate->addDays(35);
        }

        return $baseDate->addDays(2);
    }

    /**
     * Ensures all finish preconditions are satisfied before finalizing a batch.
     */
    private static function assertFinishRequirements(Production $production): void
    {
        self::assertLotsAssignedBeforeFinish($production);
        self::assertTasksCompletedBeforeFinish($production);
        self::assertRequiredQcCompletedBeforeFinish($production);
        self::assertOutputsDeclaredBeforeFinish($production);
    }

    /**
     * Ensures all required production item lots are assigned before finalizing a batch.
     */
    private static function assertLotsAssignedBeforeFinish(Production $production): void
    {
        $missingIngredientNames = $production->getMissingLotIngredientNamesForFinish();

        if ($missingIngredientNames === []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot set production to finished: missing lot selection for %s.',
            implode(', ', $missingIngredientNames),
        ));
    }

    /**
     * Ensures all active production tasks are finished before finalizing a batch.
     */
    private static function assertTasksCompletedBeforeFinish(Production $production): void
    {
        $unfinishedTaskNames = $production->getIncompleteTaskNamesForFinish();

        if ($unfinishedTaskNames === []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot set production to finished: unfinished tasks for %s.',
            implode(', ', $unfinishedTaskNames),
        ));
    }

    /**
     * Ensures all required QC checks are completed before finalizing a batch.
     */
    private static function assertRequiredQcCompletedBeforeFinish(Production $production): void
    {
        $pendingQcLabels = $production->getIncompleteRequiredQcLabelsForFinish();

        if ($pendingQcLabels === []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot set production to finished: incomplete QC checks for %s.',
            implode(', ', $pendingQcLabels),
        ));
    }

    /**
     * Ensures reconciliation outputs are declared before finalizing a batch.
     */
    private static function assertOutputsDeclaredBeforeFinish(Production $production): void
    {
        $outputBlocker = $production->getOutputBlockerMessageForFinish();

        if ($outputBlocker === null) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot set production to finished: %s.',
            $outputBlocker,
        ));
    }

    /**
     * Ensures all required production items are fully allocated before starting production.
     */
    private static function assertItemsAllocatedBeforeOngoing(Production $production): void
    {
        $unallocatedIngredientNames = $production->getUnallocatedIngredientNamesForOngoing();

        if ($unallocatedIngredientNames === []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot set production to ongoing: unallocated items for %s.',
            implode(', ', $unallocatedIngredientNames),
        ));
    }

    /**
     * Returns ingredient names missing lot assignment for finish transition.
     *
     * If a masterbatch is linked, items in the replaced phase are exempt because
     * their consumption is represented by the selected masterbatch lot.
     *
     * @return array<int, string>
     */
    public function getMissingLotIngredientNamesForFinish(int $limit = 5): array
    {
        $replacedPhase = self::resolveMasterbatchReplacedPhase($this);

        return $this->productionItems()
            ->leftJoin('ingredients', 'production_items.ingredient_id', '=', 'ingredients.id')
            ->whereNull('production_items.supply_id')
            ->when($replacedPhase !== null, function ($query) use ($replacedPhase): void {
                $query->where('production_items.phase', '!=', $replacedPhase);
            })
            ->select([
                'production_items.id as production_item_id',
                'ingredients.name as ingredient_name',
            ])
            ->get()
            ->map(fn (object $row): string => (string) ($row->ingredient_name ?: 'Item #'.$row->production_item_id))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Returns ingredient names still unallocated for ongoing transition.
     *
     * @return array<int, string>
     */
    public function getUnallocatedIngredientNamesForOngoing(int $limit = 5): array
    {
        $replacedPhase = self::resolveMasterbatchReplacedPhase($this);

        return $this->productionItems()
            ->with(['ingredient:id,name', 'allocations'])
            ->when($replacedPhase !== null, function ($query) use ($replacedPhase): void {
                $query->where('phase', '!=', $replacedPhase);
            })
            ->get()
            ->filter(fn (ProductionItem $item): bool => ! $item->isFullyAllocated())
            ->map(fn (ProductionItem $item): string => (string) ($item->ingredient?->name ?: 'Item #'.$item->id))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Returns active task names still unfinished for finish transition.
     *
     * Cancelled tasks are ignored because they are no longer part of the
     * production execution contract.
     *
     * @return array<int, string>
     */
    public function getIncompleteTaskNamesForFinish(int $limit = 5): array
    {
        return $this->productionTasks()
            ->whereNull('cancelled_at')
            ->where('is_finished', false)
            ->select(['id', 'name'])
            ->get()
            ->map(fn (ProductionTask $task): string => (string) ($task->name ?: 'Task #'.$task->id))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Returns required QC labels still incomplete for finish transition.
     *
     * A QC check is considered complete once it has a measured value or a
     * checked timestamp. Result value can still be fail; finish only requires
     * completion, not conformity.
     *
     * @return array<int, string>
     */
    public function getIncompleteRequiredQcLabelsForFinish(int $limit = 5): array
    {
        return $this->productionQcChecks()
            ->where('required', true)
            ->select([
                'id',
                'label',
                'code',
                'checked_at',
                'value_number',
                'value_text',
                'value_boolean',
            ])
            ->get()
            ->filter(fn (ProductionQcCheck $check): bool => ! $check->isDone())
            ->map(function (ProductionQcCheck $check): string {
                $label = trim((string) ($check->label ?? ''));

                if ($label !== '') {
                    return $label;
                }

                $code = trim((string) ($check->code ?? ''));

                return $code !== '' ? $code : 'QC #'.$check->id;
            })
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    public function getOutputBlockerMessageForFinish(): ?string
    {
        $outputs = $this->relationLoaded('productionOutputs')
            ? $this->productionOutputs
            : $this->productionOutputs()->get();

        $mainOutputs = $outputs->filter(fn (ProductionOutput $output): bool => $output->kind === ProductionOutputKind::MainProduct);

        if ($mainOutputs->count() !== 1) {
            return __('déclarer exactement une sortie principale');
        }

        $mainOutput = $mainOutputs->first();

        if (! $mainOutput instanceof ProductionOutput) {
            return __('déclarer une sortie principale valide');
        }

        if ((int) ($mainOutput->product_id ?? 0) !== (int) ($this->product_id ?? 0)) {
            return __('lier la sortie principale au produit fabriqué');
        }

        if ((float) $mainOutput->quantity < 0) {
            return __('renseigner une quantité valide pour la sortie principale');
        }

        if ($mainOutput->unit !== $this->getDefaultMainOutputUnit()) {
            return __('utiliser l\'unité :unit pour la sortie principale', [
                'unit' => $this->getDefaultMainOutputUnit(),
            ]);
        }

        $invalidReworkOutput = $outputs
            ->first(function (ProductionOutput $output): bool {
                return $output->kind === ProductionOutputKind::ReworkMaterial
                    && (! $output->ingredient_id || $output->unit !== 'kg' || (float) $output->quantity <= 0);
            });

        if ($invalidReworkOutput instanceof ProductionOutput) {
            return __('compléter correctement la sortie rebatch');
        }

        $invalidScrapOutput = $outputs
            ->first(function (ProductionOutput $output): bool {
                return $output->kind === ProductionOutputKind::Scrap
                    && ($output->product_id !== null || $output->ingredient_id !== null || $output->unit !== 'kg' || (float) $output->quantity <= 0);
            });

        if ($invalidScrapOutput instanceof ProductionOutput) {
            return __('compléter correctement la sortie rebut');
        }

        $hasPositiveOutput = $outputs->contains(fn (ProductionOutput $output): bool => (float) $output->quantity > 0);

        if (! $hasPositiveOutput) {
            return __('renseigner au moins une quantité de sortie positive');
        }

        return null;
    }

    private static function resolveMasterbatchReplacedPhase(Production $production): ?string
    {
        if (! $production->masterbatch_lot_id) {
            return null;
        }

        $masterbatch = $production->relationLoaded('masterbatchLot')
            ? $production->masterbatchLot
            : self::query()->select(['id', 'replaces_phase'])->find($production->masterbatch_lot_id);

        if (! $masterbatch || ! filled($masterbatch->replaces_phase)) {
            return null;
        }

        return match ((string) $masterbatch->replaces_phase) {
            'saponified_oils' => '10',
            'lye' => '20',
            'additives' => '30',
            default => (string) $masterbatch->replaces_phase,
        };
    }

    private function resolveProducedIngredientIdForOutput(): ?int
    {
        if ($this->produced_ingredient_id) {
            return (int) $this->produced_ingredient_id;
        }

        if ($this->relationLoaded('product') && $this->product?->produced_ingredient_id) {
            return (int) $this->product->produced_ingredient_id;
        }

        if (! $this->product_id) {
            return null;
        }

        $productIngredientId = Product::query()
            ->whereKey((int) $this->product_id)
            ->value('produced_ingredient_id');

        return $productIngredientId ? (int) $productIngredientId : null;
    }

    private static function assertMasterbatchLotIsFinished(Production $production): void
    {
        $masterbatch = self::query()
            ->select(['id', 'is_masterbatch', 'status'])
            ->find($production->masterbatch_lot_id);

        if (! $masterbatch || ! $masterbatch->is_masterbatch) {
            throw new InvalidArgumentException('Selected lot is not a valid masterbatch production.');
        }

        if ($masterbatch->status !== ProductionStatus::Finished) {
            throw new InvalidArgumentException('Masterbatch lot must be finished before assignment.');
        }
    }

    private static function assertWaveIsAssignable(Production $production): void
    {
        $wave = ProductionWave::query()
            ->select(['id', 'status'])
            ->find($production->production_wave_id);

        if (! $wave) {
            throw new InvalidArgumentException('Selected wave does not exist.');
        }

        if (! in_array($wave->status, [WaveStatus::Draft, WaveStatus::Approved], true)) {
            throw new InvalidArgumentException('Productions can only be linked to draft or approved waves.');
        }
    }

    private static function shouldValidateLineDailyCapacity(Production $production): bool
    {
        if (! filled($production->production_line_id) || ! filled($production->production_date)) {
            return false;
        }

        $status = $production->status instanceof ProductionStatus
            ? $production->status
            : ProductionStatus::tryFrom((string) $production->status);

        if (! in_array($status, [ProductionStatus::Planned, ProductionStatus::Confirmed], true)) {
            return false;
        }

        if (! $production->exists) {
            return true;
        }

        return $production->isDirty('production_line_id')
            || $production->isDirty('production_date')
            || $production->isDirty('status');
    }

    /**
     * Whether the allowed-line assignment guard should run for this save.
     *
     * Skips when either production_line_id or product_type_id is absent.
     * On create it always runs; on update it runs only when those fields
     * have changed, avoiding unnecessary queries on unrelated updates.
     */
    private static function shouldValidateAllowedProductionLineAssignment(Production $production): bool
    {
        if (! filled($production->production_line_id) || ! filled($production->product_type_id)) {
            return false;
        }

        if (! $production->exists) {
            return true;
        }

        return $production->isDirty('production_line_id') || $production->isDirty('product_type_id');
    }

    /**
     * Assert that the assigned production line is in the allowed set for the product type.
     *
     * Silently passes when:
     * - The product type does not exist.
     * - The product type has no allowed-line restrictions (open/backward-compatible mode).
     *
     * @throws InvalidArgumentException When the line is not in the allowed set.
     */
    private static function assertAllowedProductionLineAssignment(Production $production): void
    {
        $productType = ProductType::query()
            ->with('allowedProductionLines')
            ->find((int) $production->product_type_id);

        if (! $productType || ! $productType->hasAllowedProductionLineRestrictions()) {
            return;
        }

        if ($productType->allowsProductionLine((int) $production->production_line_id)) {
            return;
        }

        throw new InvalidArgumentException(__('La ligne de production sélectionnée n\'est pas autorisée pour ce type de produit.'));
    }

    private static function assertLineDailyCapacity(Production $production): void
    {
        $line = ProductionLine::query()->find((int) $production->production_line_id);

        if (! $line) {
            return;
        }

        $targetDate = Carbon::parse((string) $production->production_date)->toDateString();
        $capacity = $line->resolveDailyCapacity();

        $plannedCountOnDate = self::query()
            ->where('production_line_id', $line->id)
            ->whereDate('production_date', $targetDate)
            ->whereIn('status', [
                ProductionStatus::Planned->value,
                ProductionStatus::Confirmed->value,
            ])
            ->when($production->exists, fn ($query) => $query->where('id', '!=', $production->id))
            ->count();

        if ($plannedCountOnDate >= $capacity) {
            throw new InvalidArgumentException(__('Capacité journalière dépassée pour :line le :date (:capacity max).', [
                'line' => $line->name,
                'date' => Carbon::parse($targetDate)->format('d/m/Y'),
                'capacity' => $capacity,
            ]));
        }
    }

    /**
     * Convert to calendar event for Guava Calendar.
     */
    public function toCalendarEvent(): CalendarEvent
    {
        return $this->toCalendarEventForDateColumn('production_date');
    }

    public function toCalendarEventForDateColumn(string $dateColumn): CalendarEvent
    {
        $productName = (string) ($this->product?->name ?? __('Sans nom'));
        $calendarDate = $this->resolveCalendarDate($dateColumn);
        $event = CalendarEvent::make($this)
            ->title($productName)
            ->start($calendarDate)
            ->end($calendarDate)
            ->allDay()
            ->backgroundColor($this->getCalendarColor())
            ->textColor('#ffffff')
            ->extendedProps([
                'productName' => $productName,
                'lotLabel' => $this->getCalendarLotLabel(),
                'temporaryLot' => (string) $this->batch_number,
                'permanentLot' => (string) ($this->permanent_batch_number ?? ''),
                'status' => $this->status->value,
                'statusLabel' => $this->status->getLabel(),
                'lineLabel' => $this->getCalendarLineLabel(),
                'lineBadge' => $this->getCalendarLineBadge(),
                'quantityLabel' => $this->getCalendarQuantityLabel(),
                'unitsLabel' => $this->getCalendarUnitsLabel(),
                'waveLabel' => $this->getCalendarWaveLabel(),
                'readyDateLabel' => $this->ready_date?->format('d/m/Y'),
                'dateBasis' => $dateColumn,
                'eventType' => 'production',
            ])
            ->action('edit');

        $productionUrl = $this->resolveCalendarProductionUrl();

        if ($productionUrl !== null) {
            $event->url($productionUrl, '_self');
        }

        return $event;
    }

    private function resolveCalendarDate(string $dateColumn): Carbon
    {
        $date = $this->{$dateColumn};

        if ($date instanceof Carbon) {
            return $date;
        }

        return Carbon::parse((string) $date);
    }

    /**
     * Get calendar color based on status.
     */
    private function getCalendarColor(): string
    {
        return match ($this->status) {
            ProductionStatus::Planned => '#64748b', // slate-500
            ProductionStatus::Confirmed => '#3b82f6', // blue-500
            ProductionStatus::Ongoing => '#f59e0b', // amber-500
            ProductionStatus::Finished => '#10b981', // emerald-500
            ProductionStatus::Cancelled => '#ef4444', // red-500
        };
    }

    private function getCalendarLotLabel(): string
    {
        if (! filled($this->permanent_batch_number)) {
            return (string) $this->batch_number;
        }

        return $this->permanent_batch_number.' ('.(string) $this->batch_number.')';
    }

    private function getCalendarLineLabel(): string
    {
        return (string) ($this->productionLine?->name ?? __('Sans ligne'));
    }

    private function getCalendarLineBadge(): string
    {
        if (! $this->productionLine?->name) {
            return __('Sans ligne');
        }

        $lineName = trim($this->productionLine->name);

        if (preg_match('/(\d+)/', $lineName, $matches) === 1) {
            return 'L'.$matches[1];
        }

        $normalized = Str::of($lineName)
            ->replaceStart('Ligne ', '')
            ->replaceStart('ligne ', '')
            ->trim();

        return Str::limit((string) $normalized, 12, '');
    }

    private function getCalendarQuantityLabel(): string
    {
        if (! filled($this->planned_quantity)) {
            return __('Qté inconnue');
        }

        $quantity = rtrim(rtrim(number_format((float) $this->planned_quantity, 3, ',', ' '), '0'), ',');

        return __(':quantity kg', ['quantity' => $quantity]);
    }

    private function getCalendarUnitsLabel(): ?string
    {
        if (! filled($this->expected_units)) {
            return null;
        }

        $units = number_format((float) $this->expected_units, 0, ',', ' ');

        return __(':units u.', ['units' => $units]);
    }

    private function getCalendarWaveLabel(): ?string
    {
        $waveName = trim((string) ($this->wave?->name ?? ''));

        return $waveName !== '' ? $waveName : null;
    }

    private function resolveCalendarProductionUrl(): ?string
    {
        try {
            return ProductionResource::getUrl('view', ['record' => $this]);
        } catch (\Throwable) {
            return null;
        }
    }
}
