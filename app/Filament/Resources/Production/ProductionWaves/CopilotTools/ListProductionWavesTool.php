<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionWaves\CopilotTools;

use App\Models\Production\ProductionWave;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListProductionWavesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'List production waves in read-only mode with their status and dates.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of waves to return')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $waves = ProductionWave::query()
            ->withCount('productions')
            ->latest('id')
            ->limit($limit)
            ->get();

        if ($waves->isEmpty()) {
            return 'No production waves were found.';
        }

        return $waves->map(function (ProductionWave $wave): string {
            return sprintf(
                '#%s | %s | %s | productions: %d | %s -> %s',
                $wave->id,
                $wave->name,
                $wave->status->value,
                $wave->productions_count,
                optional($wave->planned_start_date)->format('Y-m-d') ?? '-',
                optional($wave->planned_end_date)->format('Y-m-d') ?? '-',
            );
        })->implode("\n");
    }
}
