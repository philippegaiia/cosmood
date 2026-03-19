<?php

declare(strict_types=1);

namespace App\Filament\Pages\CopilotTools\WaveProcurementOverview;

use App\Models\Production\ProductionWave;
use App\Models\Supply\SupplierOrder;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetProcurementOverviewSummaryTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Summarize wave procurement in read-only mode: active waves, linked supplier orders, and what still looks unsecured at a high level.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of waves to summarize')->default(5),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(10, (int) ($request['limit'] ?? 5)));

        $waves = ProductionWave::query()
            ->withCount('productions')
            ->whereIn('status', ['draft', 'approved', 'in_progress'])
            ->orderByRaw('planned_start_date is null, planned_start_date asc')
            ->limit($limit)
            ->get();

        $linkedOrders = SupplierOrder::query()
            ->whereNotNull('production_wave_id')
            ->count();

        $lines = [
            'Procurement overview summary:',
            '- Active waves: '.$waves->count(),
            '- Supplier orders linked to a wave: '.$linkedOrders,
        ];

        if ($waves->isEmpty()) {
            $lines[] = '- No active waves found.';

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = 'Upcoming waves:';

        foreach ($waves as $wave) {
            $linkedOrderCount = SupplierOrder::query()->where('production_wave_id', $wave->id)->count();

            $lines[] = sprintf(
                '- %s | %s | productions: %d | linked orders: %d | start: %s',
                $wave->name,
                $wave->status->value,
                $wave->productions_count,
                $linkedOrderCount,
                optional($wave->planned_start_date)->format('Y-m-d') ?? '-',
            );
        }

        return implode("\n", $lines);
    }
}
