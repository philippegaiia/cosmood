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
     *     lines: array<int, array{id: int|null, key: string, name: string, capacity: int|null}>,
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

        $targetDate = Carbon::parse($date);
        $consumesCapacityOnTargetDay = $production->productionTasks
            ->filter(fn (ProductionTask $task): bool => $task->scheduled_date?->toDateString() === $targetDate->toDateString())
            ->contains(fn (ProductionTask $task): bool => (bool) ($task->productionTaskType?->is_capacity_consuming ?? false) && ! $task->isCancelled());

        if ($lineId !== null && $consumesCapacityOnTargetDay && ! app(LineDayOccupancyService::class)->hasCapacity($lineId, $targetDate, $production->id)) {
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

        $production->update([
            'production_line_id' => $lineId,
            'production_date' => $targetDate->toDateString(),
        ]);

        $this->reload();
    }

    public function moveTask(int $taskId, string $date): void
    {
        $task = ProductionTask::query()->find($taskId);

        if (! $task) {
            $this->reload();

            return;
        }

        try {
            app(TaskGenerationService::class)->setManualSchedule($task, Carbon::parse($date));
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->info()
                ->title(__('Déplacement impossible'))
                ->body(__($exception->getMessage()))
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
     * @return array{
     *     lines: array<int, array{id: int|null, key: string, name: string, capacity: int|null}>,
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
            ],
        ])->merge($lines->map(fn (ProductionLine $line): array => [
            'id' => $line->id,
            'key' => (string) $line->id,
            'name' => $line->is_active ? $line->name : $line->name.' (inactive)',
            'capacity' => $line->resolveDailyCapacity(),
        ]))
            ->when($this->onlyUnassigned, fn (Collection $collection): Collection => $collection->take(1))
            ->when($this->filterLineId !== null, function (Collection $collection): Collection {
                return $collection->filter(fn (array $row): bool => $row['id'] === null || $row['id'] === $this->filterLineId)->values();
            })
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
                    'tasks' => [],
                ];
            }
        }

        foreach ($productions as $production) {
            $lineKey = $production->production_line_id ? (string) $production->production_line_id : 'unassigned';
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
            ];

            $tasksInRange = $production->productionTasks
                ->filter(fn (ProductionTask $task): bool => $task->scheduled_date !== null);

            if ($tasksInRange->isEmpty()) {
                $day = $production->production_date?->toDateString();

                if ($day !== null && isset($cells[$lineKey][$day])) {
                    $cells[$lineKey][$day]['productions'][] = $card;
                    $cells[$lineKey][$day]['has_issue'] = $cells[$lineKey][$day]['has_issue'] || $hasIssue;
                }

                continue;
            }

            $tasksByDay = $tasksInRange->groupBy(fn (ProductionTask $task): string => $task->scheduled_date->toDateString());

            foreach ($tasksByDay as $day => $tasks) {
                if (! isset($cells[$lineKey][$day])) {
                    continue;
                }

                $cells[$lineKey][$day]['productions'][] = $card;
                $cells[$lineKey][$day]['has_issue'] = $cells[$lineKey][$day]['has_issue'] || $hasIssue;

                foreach ($tasks as $task) {
                    $cells[$lineKey][$day]['tasks'][] = [
                        'id' => $task->id,
                        'name' => (string) $task->name,
                        'production_id' => $production->id,
                        'scheduled_date' => $task->scheduled_date?->toDateString(),
                        'is_capacity_consuming' => (bool) ($task->productionTaskType?->is_capacity_consuming ?? false),
                        'is_cancelled' => $task->isCancelled(),
                        'is_finished' => (bool) $task->is_finished,
                    ];
                }
            }
        }

        if ($this->onlyIssues) {
            foreach ($cells as $lineKey => $lineCells) {
                foreach ($lineCells as $day => $cell) {
                    $cells[$lineKey][$day]['productions'] = collect($cell['productions'])
                        ->filter(fn (array $production): bool => $production['has_issue'])
                        ->values()
                        ->all();

                    $cells[$lineKey][$day]['tasks'] = $cell['has_issue'] ? $cell['tasks'] : [];
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
}
