<?php

declare(strict_types=1);

namespace App\Filament\Pages\CopilotTools\PlanningBoard;

use App\Models\Production\Production;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetPlanningBoardSummaryTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Summarize the planning board in read-only mode: upcoming productions, load by line, and unassigned batches.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('How many upcoming days to summarize')->default(14),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $days = max(1, min(30, (int) ($request['days'] ?? 14)));
        $startDate = now()->startOfDay();
        $endDate = now()->addDays($days)->endOfDay();

        $productions = Production::query()
            ->with(['productionLine:id,name', 'product:id,name'])
            ->whereBetween('production_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderBy('production_date')
            ->orderBy('batch_number')
            ->get();

        if ($productions->isEmpty()) {
            return "No productions are planned in the next {$days} days.";
        }

        $lines = [
            "Planning summary for the next {$days} days:",
            '- Total productions: '.$productions->count(),
            '- Unassigned to a line: '.$productions->whereNull('production_line_id')->count(),
            '',
            'Load by line:',
        ];

        foreach ($productions->groupBy(fn (Production $production): string => $production->productionLine?->name ?? 'Without line') as $lineName => $group) {
            $lines[] = '- '.$lineName.': '.$group->count().' production(s)';
        }

        $lines[] = '';
        $lines[] = 'Next productions:';

        foreach ($productions->take(10) as $production) {
            $lines[] = sprintf(
                '- %s | %s | %s | %s',
                $production->batch_number,
                $production->product?->name ?? 'Unknown product',
                optional($production->production_date)->format('Y-m-d') ?? 'No date',
                $production->productionLine?->name ?? 'Without line',
            );
        }

        return implode("\n", $lines);
    }
}
