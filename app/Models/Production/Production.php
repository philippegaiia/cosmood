<?php

namespace App\Models\Production;

use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Enums\WaveStatus;
use App\Filament\Resources\Production\ProductionResource;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use Carbon\Carbon;
use Guava\Calendar\Contracts\Eventable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class Production extends Model implements Eventable
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (Production $production): void {
            if ($production->isDirty('production_wave_id') && $production->production_wave_id !== null) {
                self::assertWaveIsAssignable($production);
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
                self::assertLotsAssignedBeforeFinish($production);
            }
        });

        static::deleting(function (Production $production): void {
            if ($production->status === ProductionStatus::Finished) {
                throw new InvalidArgumentException('Finished productions cannot be deleted. Cancel before deletion if needed.');
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
                ProductionStatus::Cancelled,
            ],
            ProductionStatus::Confirmed->value => [
                ProductionStatus::Ongoing,
                ProductionStatus::Cancelled,
            ],
            ProductionStatus::Ongoing->value => [
                ProductionStatus::Finished,
                ProductionStatus::Cancelled,
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
    public function toCalendarEvent(): \Guava\Calendar\ValueObjects\CalendarEvent
    {
        $productName = (string) ($this->product?->name ?? __('Sans nom'));
        $event = \Guava\Calendar\ValueObjects\CalendarEvent::make($this)
            ->title($productName)
            ->start($this->production_date)
            ->end($this->production_date)
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
                'eventType' => 'production',
            ])
            ->action('edit');

        $productionUrl = $this->resolveCalendarProductionUrl();

        if ($productionUrl !== null) {
            $event->url($productionUrl, '_self');
        }

        return $event;
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

    private function resolveCalendarProductionUrl(): ?string
    {
        try {
            return ProductionResource::getUrl('view', ['record' => $this]);
        } catch (\Throwable) {
            return null;
        }
    }
}
