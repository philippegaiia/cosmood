<?php

namespace App\Filament\Widgets;

use App\Enums\ProductionStatus;
use App\Models\Production\ProductCategory;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Production Calendar Widget using Guava Calendar.
 *
 * Shows:
 * - Productions only, for medium-term planning visibility
 * - Product, lot, expected quantity, expected units and wave reference
 *
 * Default view: Month
 * Read-only: all scheduling actions stay on the planning board
 */
class ProductionCalendarWidget extends CalendarWidget
{
    public string $dateBasis = 'production_date';

    public ?string $statusFilter = null;

    public ?int $lineFilter = null;

    public ?int $productCategoryFilter = null;

    public bool $onlyUnassigned = false;

    protected HtmlString|string|bool|null $heading = 'Calendrier production';

    protected int|string|array $columnSpan = 'full';

    protected CalendarViewType $calendarView = CalendarViewType::DayGridMonth;

    protected bool $dayMaxEvents = true;

    /**
     * @var array<string, mixed>
     */
    protected array $options = [
        'height' => 'auto',
        'contentHeight' => 'auto',
        'displayEventEnd' => false,
        'allDaySlot' => true,
        'slotMinTime' => '00:00:00',
        'slotMaxTime' => '01:00:00',
        'headerToolbar' => [
            'start' => 'prev,next today',
            'center' => 'title',
            'end' => 'dayGridMonth,timeGridWeek,listMonth',
        ],
        'buttonText' => [
            'today' => 'Aujourd\'hui',
            'dayGridMonth' => 'Mois',
            'timeGridWeek' => 'Semaine',
            'listMonth' => 'Liste',
        ],
    ];

    protected ?string $locale = 'fr';

    /** Calendar remains read-only. */
    protected bool $eventDragEnabled = false;

    /** Enable click handling so event URLs are opened. */
    protected bool $eventClickEnabled = true;

    /**
     * Get production events for the selected horizon/filter mode.
     */
    protected function getEvents(FetchInfo $info): Collection|array|Builder
    {
        $dateColumn = $this->getCalendarDateColumn();

        $query = Production::query()
            ->with(['product.productCategory', 'productionLine', 'wave'])
            ->whereNotNull($dateColumn)
            ->whereBetween($dateColumn, [$info->start, $info->end]);

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->productCategoryFilter) {
            $query->whereHas('product', fn (Builder $productQuery) => $productQuery->where('product_category_id', $this->productCategoryFilter));
        }

        if ($this->onlyUnassigned) {
            $query->whereNull('production_line_id');
        } elseif ($this->lineFilter) {
            $query->where('production_line_id', $this->lineFilter);
        }

        return $query
            ->orderBy($dateColumn)
            ->get()
            ->map(fn (Production $production): CalendarEvent => $production->toCalendarEventForDateColumn($dateColumn));
    }

    protected function eventContent(): string
    {
        return view('filament.widgets.production-calendar.event')->render();
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('filters')
                ->label(__('Filtres'))
                ->icon('heroicon-o-funnel')
                ->fillForm(fn (): array => [
                    'dateBasis' => $this->dateBasis,
                    'statusFilter' => $this->statusFilter,
                    'productCategoryFilter' => $this->productCategoryFilter,
                    'lineFilter' => $this->lineFilter,
                    'onlyUnassigned' => $this->onlyUnassigned,
                ])
                ->schema([
                    Select::make('dateBasis')
                        ->label(__('Afficher par'))
                        ->options([
                            'production_date' => __('Date de production'),
                            'ready_date' => __('Date de disponibilité'),
                        ])
                        ->required(),
                    Select::make('statusFilter')
                        ->label(__('Statut'))
                        ->options(collect(ProductionStatus::cases())
                            ->mapWithKeys(fn (ProductionStatus $status): array => [$status->value => $status->getLabel()])
                            ->all())
                        ->placeholder(__('Tous les statuts')),
                    Select::make('productCategoryFilter')
                        ->label(__('Catégorie produit'))
                        ->options($this->getProductCategoryFilterOptions())
                        ->placeholder(__('Toutes les catégories')),
                    Select::make('lineFilter')
                        ->label(__('Ligne'))
                        ->options($this->getLineFilterOptions())
                        ->placeholder(__('Toutes les lignes')),
                    Toggle::make('onlyUnassigned')
                        ->label(__('Sans ligne uniquement')),
                ])
                ->action(function (array $data): void {
                    $this->dateBasis = $data['dateBasis'];
                    $this->statusFilter = filled($data['statusFilter'] ?? null) ? (string) $data['statusFilter'] : null;
                    $this->productCategoryFilter = filled($data['productCategoryFilter'] ?? null) ? (int) $data['productCategoryFilter'] : null;
                    $this->lineFilter = filled($data['lineFilter'] ?? null) ? (int) $data['lineFilter'] : null;
                    $this->onlyUnassigned = (bool) ($data['onlyUnassigned'] ?? false);

                    if ($this->onlyUnassigned) {
                        $this->lineFilter = null;
                    }

                    $this->refreshRecords();
                }),
            Action::make('resetFilters')
                ->label(__('Réinitialiser'))
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->hasActiveFilters())
                ->action(function (): void {
                    $this->dateBasis = 'production_date';
                    $this->statusFilter = null;
                    $this->productCategoryFilter = null;
                    $this->lineFilter = null;
                    $this->onlyUnassigned = false;

                    $this->refreshRecords();
                }),
        ];
    }

    private function getCalendarDateColumn(): string
    {
        return $this->dateBasis === 'ready_date' ? 'ready_date' : 'production_date';
    }

    /**
     * @return array<int, string>
     */
    private function getLineFilterOptions(): array
    {
        return ProductionLine::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function getProductCategoryFilterOptions(): array
    {
        return ProductCategory::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function hasActiveFilters(): bool
    {
        return $this->dateBasis !== 'production_date'
            || $this->statusFilter !== null
            || $this->productCategoryFilter !== null
            || $this->lineFilter !== null
            || $this->onlyUnassigned;
    }
}
