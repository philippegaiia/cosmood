<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionResource\CopilotTools;

use App\Models\Production\Production;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchProductionsTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search production batches by batch number or product name in read-only mode.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Batch number or product name')->required(),
            'limit' => $schema->integer()->description('Maximum number of results')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request['query']);
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $productions = Production::query()
            ->with('product:id,name')
            ->where('batch_number', 'like', "%{$query}%")
            ->orWhereHas('product', fn ($productQuery) => $productQuery->where('name', 'like', "%{$query}%"))
            ->limit($limit)
            ->get();

        if ($productions->isEmpty()) {
            return "No productions matched '{$query}'.";
        }

        return $productions->map(fn (Production $production): string => sprintf(
            '#%s | %s | %s | %s',
            $production->id,
            $production->batch_number,
            $production->product?->name ?? '-',
            $production->status->value,
        ))->implode("\n");
    }
}
