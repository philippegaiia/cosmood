<?php

namespace App\Livewire\Production;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionTask;
use App\Services\Production\LineDayOccupancyService;
use App\Services\Production\TaskGenerationService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class PlanningBoard extends Component
{
    public string $weekStart = '';

    public ?int $filterLineId = null;

    public ?string $filterStatus = null;

    public bool $showTasks = true;

    public bool $showProductions = true;

    public bool $onlyIssues = false;

    public bool $onlyUnassigned = false;

    /**
     * @var array{
     *     lines: array<int, array{id: int|null, key: string, name: string, capacity: int|null, type: string}>,
     *     days: array<int, string>,
     *     cells: array<string, array<string, array<string, mixed>>>
     * }
     */
    public array $board = [
        'lines' => [],
        'days' => [],
        'cells' => [],
    ];

    public function mount(): void
    {
        $this->weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->reload();
    }

    public function previousWeek(): void
    {
        $this->weekStart = $this->resolveWeekStart()->subWeek()->toDateString();
        $this->reload();
    }

    public function nextWeek(): void
    {
        $this->weekStart = $this->resolveWeekStart()->addWeek()->toDateString();
        $this->reload();
    }

    public function updatedFilterLineId(): void
    {
        $this->reload();
    }

    public function updatedFilterStatus(): void
    {
        $this->reload();
    }

    public function updatedShowTasks(): void
    {
        $this->reload();
    }

    public function updatedShowProductions(): void
    {
        $this->reload();
    }

    public function updatedOnlyIssues(): void
    {
        $this->reload();
    }

    public function updatedOnlyUnassigned(): void
    {
        $this->reload();
    }

    public function moveProduction(int $productionId, ?int $lineId, string $date): void
    {
        $production = Production::query()
            ->with('productType.allowedProductionLines', 'productionTasks.productionTaskType')
            ->find($productionId);

        if (! $production) {
            $this->reload();

            return;
        }

        if (in_array($production->status, [ProductionStatus::Ongoing, ProductionStatus::Finished, ProductionStatus::Cancelled], true)) {
            Notification::make()
                ->info()
                ->title(__('Déplacement impossible'))
                ->body($production->status === ProductionStatus::Ongoing
                    ? __('Les productions en cours ne peuvent pas être déplacées.')
                    : __('Cette production ne peut plus être déplacée.'))
                ->send();

            $this->reload();

            return;
        }

        if ($lineId !== null && $production->productType && ! $production->productType->allowsProductionLine($lineId)) {
            $lineName = ProductionLine::query()->whereKey($lineId)->value('name') ?? __('Ligne inconnue');

            Notification::make()
                ->warning()
                ->title(__('Ligne non autorisée'))
                ->body(__('Ligne :line non autorisée pour ce type de produit.', ['line' => $lineName]))
                ->send();

            $this->reload();

            return;
        }

        $targetDate = $this->resolveDropDate($date);

        if (! $targetDate) {
            Notification::make()
                ->warning()
                ->title(__('Date invalide'))
                ->body(__('La date de déplacement est invalide.'))
                ->send();

            $this->reload();

            return;
        }

        if ($lineId !== null && ! app(LineDayOccupancyService::class)->hasCapacity($lineId, $targetDate, $production->id)) {
            $line = ProductionLine::query()->find($lineId);
            $occupancy = app(LineDayOccupancyService::class)->getOccupancy([$lineId], $targetDate->copy(), $targetDate->copy());
            $cell = $occupancy[$lineId][$targetDate->toDateString()] ?? [
                'used' => 0,
                'capacity' => $line?->resolveDailyCapacity() ?? 0,
            ];

            Notification::make()
                ->danger()
                ->title(__('Capacité pleine'))
                ->body(__('Capacité pleine sur :line le :date (:used/:capacity).', [
                    'line' => $line?->name ?? __('Ligne inconnue'),
                    'date' => $targetDate->format('d/m/Y'),
                    'used' => $cell['used'],
                    'capacity' => $cell['capacity'],
                ]))
                ->send();

            $this->reload();

            return;
        }

        try {
            $production->update([
                'production_line_id' => $lineId,
                'production_date' => $targetDate->toDateString(),
            ]);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->warning()
                ->title(__('Déplacement impossible'))
                ->body($exception->getMessage())
                ->send();
        }

        $this->reload();
    }

    public function moveTask(int $taskId, string $date): void
    {
        $task = ProductionTask::query()->find($taskId);

        if (! $task) {
            $this->reload();

            return;
        }

        $targetDate = $this->resolveDropDate($date);

        if (! $targetDate) {
            Notification::make()
                ->warning()
                ->title(__('Date invalide'))
                ->body(__('La date de déplacement est invalide.'))
                ->send();

            $this->reload();

            return;
        }

        try {
            app(TaskGenerationService::class)->setManualSchedule($task, $targetDate);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->info()
                ->title(__('Déplacement impossible'))
                ->body($exception->getMessage())
                ->send();
        }

        $this->reload();
    }

    public function reload(): void
    {
        $this->board = $this->buildBoard();
    }

    public function render(): View
    {
        return view('livewire.production.planning-board');
    }

    /**
     * Build the weekly planning board with separated capacity and execution layers.
     *
     * Capacity rows are production-first and use `production_date`.
     * The dedicated `tasks` row is execution-first and uses `scheduled_date`.
     * This separation is intentional: task timing must stay visible without
     * implying that later curing/packaging/labeling still occupies the line.
     *
     * @return array{
     *     lines: array<int, array{id: int|null, key: string, name: string, capacity: int|null, type: string}>,
     *     days: array<int, string>,
     *     cells: array<string, array<string, array<string, mixed>>>
     * }
     */
    private function buildBoard(): array
    {
        $from = $this->resolveWeekStart();
        $to = $from->copy()->addDays(6);
        $days = collect(range(0, 6))
            ->map(fn (int $offset): string => $from->copy()->addDays($offset)->toDateString())
            ->all();

        $productions = Production::query()
            ->with([
                'productType.allowedProductionLines',
                'product',
                'productionLine',
                'productionTasks' => fn ($query) => $query
                    ->with('productionTaskType')
                    ->whereDate('scheduled_date', '>=', $from->toDateString())
                    ->whereDate('scheduled_date', '<=', $to->toDateString())
                    ->orderBy('scheduled_date')
                    ->orderBy('sequence_order'),
            ])
            ->where(function ($query) use ($from, $to): void {
                $query
                    ->whereHas('productionTasks', function ($taskQuery) use ($from, $to): void {
                        $taskQuery
                            ->whereDate('scheduled_date', '>=', $from->toDateString())
                            ->whereDate('scheduled_date', '<=', $to->toDateString());
                    })
                    ->orWhereBetween('production_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->when($this->filterStatus, fn ($query) => $query->where('status', $this->filterStatus))
            ->get();

        $visibleLineIds = $productions
            ->pluck('production_line_id')
            ->filter()
            ->map(fn (mixed $lineId): int => (int) $lineId)
            ->unique()
            ->values()
            ->all();

        $lines = ProductionLine::query()
            ->where(function ($query) use ($visibleLineIds): void {
                $query->where('is_active', true);

                if ($visibleLineIds !== []) {
                    $query->orWhereIn('id', $visibleLineIds);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $occupancy = app(LineDayOccupancyService::class)->getOccupancy($lines->modelKeys(), $from, $to);

        $rows = collect([
            [
                'id' => null,
                'key' => 'unassigned',
                'name' => __('Sans ligne'),
                'capacity' => null,
                'type' => 'production',
            ],
        ])->merge($lines->map(fn (ProductionLine $line): array => [
            'id' => $line->id,
            'key' => (string) $line->id,
            'name' => $line->is_active ? $line->name : $line->name.' (inactive)',
            'capacity' => $line->resolveDailyCapacity(),
            'type' => 'production',
        ]))
            ->when($this->onlyUnassigned, fn (Collection $collection): Collection => $collection->take(1))
            ->when($this->filterLineId !== null, function (Collection $collection): Collection {
                return $collection->filter(fn (array $row): bool => $row['id'] === null || $row['id'] === $this->filterLineId)->values();
            })
            ->push([
                'id' => null,
                'key' => 'tasks',
                'name' => __('Tâches'),
                'capacity' => null,
                'type' => 'tasks',
            ])
            ->values();

        $cells = [];

        foreach ($rows as $row) {
            foreach ($days as $day) {
                $baseCell = $row['id'] === null
                    ? [
                        'used' => 0,
                        'capacity' => 0,
                        'is_near_capacity' => false,
                        'is_over_capacity' => false,
                        'is_closed' => false,
                        'has_issue' => false,
                    ]
                    : ($occupancy[$row['id']][$day] ?? [
                        'used' => 0,
                        'capacity' => $row['capacity'] ?? 0,
                        'is_near_capacity' => false,
                        'is_over_capacity' => false,
                        'is_closed' => false,
                        'has_issue' => false,
                    ]);

                $cells[$row['key']][$day] = [
                    ...$baseCell,
                    'productions' => [],
                    'task_groups' => [],
                ];
            }
        }

        foreach ($productions as $production) {
            $lineKey = $production->production_line_id ? (string) $production->production_line_id : 'unassigned';
            $productionDate = $production->production_date?->toDateString();
            $isLineAllowed = $production->productType?->allowsProductionLine($production->production_line_id) ?? true;
            $isUnassigned = $production->production_line_id === null;
            $hasIssue = ! $isLineAllowed || ($isUnassigned && $production->status === ProductionStatus::Confirmed);

            $card = [
                'id' => $production->id,
                'product_name' => (string) ($production->product?->name ?? __('Produit sans nom')),
                'batch_ref' => (string) $production->batch_number,
                'status' => $production->status->value,
                'is_draggable' => in_array($production->status, [ProductionStatus::Planned, ProductionStatus::Confirmed], true),
                'is_unassigned' => $isUnassigned,
                'is_line_allowed' => $isLineAllowed,
                'has_issue' => $hasIssue,
                'production_line' => $production->productionLine?->name,
                'tasks' => [],
                'is_task_reference' => false,
                'task_date' => $productionDate,
                'production_date' => $productionDate,
            ];

            $tasksInRange = $production->productionTasks
                ->filter(fn (ProductionTask $task): bool => $task->scheduled_date !== null);

            $tasksByDay = $tasksInRange
                ->groupBy(fn (ProductionTask $task): string => $task->scheduled_date->toDateString())
                ->map(fn (Collection $tasks): array => $tasks
                    ->map(fn (ProductionTask $task): array => $this->buildTaskPayload($production, $task))
                    ->values()
                    ->all());

            if ($productionDate !== null && isset($cells[$lineKey][$productionDate])) {
                $cells[$lineKey][$productionDate]['productions'][] = [
                    ...$card,
                    'tasks' => $tasksByDay->get($productionDate, []),
                ];
                $cells[$lineKey][$productionDate]['has_issue'] = $cells[$lineKey][$productionDate]['has_issue'] || $hasIssue;
            }

            if ($tasksByDay->isEmpty()) {
                continue;
            }

            foreach ($tasksByDay as $day => $taskPayloads) {
                if (! isset($cells['tasks'][$day]) || ! $this->shouldShowTaskGroupForProduction($production)) {
                    continue;
                }

                $cells['tasks'][$day]['task_groups'][] = [
                    ...$card,
                    'tasks' => $taskPayloads,
                    'is_task_reference' => true,
                    'task_date' => $day,
                    'matches_production_date' => $productionDate === $day,
                ];
                $cells['tasks'][$day]['has_issue'] = $cells['tasks'][$day]['has_issue'] || $hasIssue;
            }
        }

        if ($this->onlyIssues) {
            foreach ($cells as $lineKey => $lineCells) {
                foreach ($lineCells as $day => $cell) {
                    if (! $cell['has_issue']) {
                        $cells[$lineKey][$day]['productions'] = [];
                        $cells[$lineKey][$day]['task_groups'] = [];

                        continue;
                    }

                    if (! $cell['is_over_capacity']) {
                        $cells[$lineKey][$day]['productions'] = collect($cell['productions'])
                            ->filter(fn (array $production): bool => $production['has_issue'])
                            ->values()
                            ->all();

                        $cells[$lineKey][$day]['task_groups'] = collect($cell['task_groups'])
                            ->filter(fn (array $taskGroup): bool => $taskGroup['has_issue'])
                            ->values()
                            ->all();
                    }
                }
            }
        }

        return [
            'lines' => $rows->all(),
            'days' => $days,
            'cells' => $cells,
        ];
    }

    private function resolveWeekStart(): Carbon
    {
        return Carbon::parse($this->weekStart)->startOfWeek(Carbon::MONDAY);
    }

    private function resolveDropDate(string $date): ?Carbon
    {
        try {
            return Carbon::parse($date)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function shouldShowTaskGroupForProduction(Production $production): bool
    {
        if ($this->onlyUnassigned) {
            return $production->production_line_id === null;
        }

        if ($this->filterLineId !== null) {
            return (int) $production->production_line_id === $this->filterLineId;
        }

        return true;
    }

    private function normalizeHexColor(?string $color): string
    {
        if (! is_string($color) || ! preg_match('/^#?[0-9A-Fa-f]{6}$/', $color)) {
            return '#6b7280';
        }

        return str_starts_with($color, '#') ? $color : '#'.$color;
    }

    private function resolveReadableTextColor(string $hexColor): string
    {
        [$red, $green, $blue] = $this->hexToRgb($hexColor);
        $luminance = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

        return $luminance >= 160 ? '#111827' : '#ffffff';
    }

    private function hexToRgba(string $hexColor, float $alpha): string
    {
        [$red, $green, $blue] = $this->hexToRgb($hexColor);

        return sprintf('rgba(%d, %d, %d, %.2f)', $red, $green, $blue, $alpha);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexToRgb(string $hexColor): array
    {
        $normalized = ltrim($hexColor, '#');

        return [
            hexdec(substr($normalized, 0, 2)),
            hexdec(substr($normalized, 2, 2)),
            hexdec(substr($normalized, 4, 2)),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     production_id: int,
     *     scheduled_date: string|null,
     *     is_capacity_consuming: bool,
     *     is_cancelled: bool,
     *     is_finished: bool,
     *     is_draggable: bool,
     *     color: string,
     *     text_color: string,
     *     muted_color: string
     * }
     */
    private function buildTaskPayload(Production $production, ProductionTask $task): array
    {
        $taskColor = $this->normalizeHexColor($task->productionTaskType?->color);

        return [
            'id' => $task->id,
            'name' => (string) $task->name,
            'production_id' => $production->id,
            'scheduled_date' => $task->scheduled_date?->toDateString(),
            'is_capacity_consuming' => (bool) ($task->productionTaskType?->is_capacity_consuming ?? false),
            'is_cancelled' => $task->isCancelled(),
            'is_finished' => (bool) $task->is_finished,
            'is_draggable' => ! $task->isCancelled() && ! (bool) $task->is_finished,
            'color' => $taskColor,
            'text_color' => $this->resolveReadableTextColor($taskColor),
            'muted_color' => $this->hexToRgba($taskColor, 0.12),
        ];
    }
}
