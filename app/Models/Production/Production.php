<?php

namespace App\Models\Production;

use App\Enums\ProcurementStatus;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
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
        $this->loadMissing('productionItems');

        $items = $this->productionItems;

        if ($items->isEmpty()) {
            return 'missing';
        }

        $replacedPhase = $this->masterbatch_lot_id
            ? self::resolveMasterbatchReplacedPhase($this)
            : null;

        foreach ($items as $item) {
            if ($replacedPhase !== null && $item->phase === $replacedPhase) {
                continue;
            }

            if ($item->procurement_status === ProcurementStatus::NotOrdered) {
                return 'missing';
            }
        }

        foreach ($items as $item) {
            if ($replacedPhase !== null && $item->phase === $replacedPhase) {
                continue;
            }

            if (in_array($item->procurement_status, [ProcurementStatus::Ordered, ProcurementStatus::Confirmed], true)) {
                return 'ordered';
            }
        }

        return 'received';
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

    /**
     * Convert to calendar event for Guava Calendar.
     */
    public function toCalendarEvent(): \Guava\Calendar\ValueObjects\CalendarEvent
    {
        return \Guava\Calendar\ValueObjects\CalendarEvent::make($this)
            ->title($this->product?->name ?? 'Sans nom')
            ->start($this->production_date)
            ->end($this->production_date)
            ->allDay()
            ->backgroundColor($this->getCalendarColor())
            ->textColor('#ffffff')
            ->action('edit');
    }

    /**
     * Get calendar color based on status.
     */
    private function getCalendarColor(): string
    {
        return match ($this->status) {
            ProductionStatus::Planned => '#64748b', // slate-500
            ProductionStatus::Confirmed => '#3b82f6', // blue-500
            default => '#6b7280',
        };
    }
}
