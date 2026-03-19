<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionWaves\CopilotTools;

use App\Models\Production\ProductionWave;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchProductionWavesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search production waves by name or slug in read-only mode.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Wave name or slug')->required(),
            'limit' => $schema->integer()->description('Maximum number of results')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request['query']);
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $waves = ProductionWave::query()
            ->withCount('productions')
            ->where('name', 'like', "%{$query}%")
            ->orWhere('slug', 'like', "%{$query}%")
            ->limit($limit)
            ->get();

        if ($waves->isEmpty()) {
            return "No production waves matched '{$query}'.";
        }

        return $waves->map(fn (ProductionWave $wave): string => sprintf(
            '#%s | %s | %s | productions: %d',
            $wave->id,
            $wave->name,
            $wave->status->value,
            $wave->productions_count,
        ))->implode("\n");
    }
}
