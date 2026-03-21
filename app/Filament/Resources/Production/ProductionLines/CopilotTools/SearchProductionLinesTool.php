<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionLines\CopilotTools;

use App\Models\Production\ProductionLine;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchProductionLinesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return __('Search production lines by name');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search term for production line name')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of results to return')
                ->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = $request['query'] ?? '';
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        if (empty($query)) {
            return __('Please provide a search query.');
        }

        $lines = ProductionLine::query()
            ->withCount('productTypes')
            ->where('name', 'like', "%{$query}%")
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();

        if ($lines->isEmpty()) {
            return __('No production lines found matching your search.');
        }

        return $lines->map(fn (ProductionLine $line): string => sprintf(
            '#%s | %s | Capacity: %s/day | %s | %s product types',
            $line->id,
            $line->name,
            $line->daily_batch_capacity,
            $line->is_active ? 'active' : 'inactive',
            $line->product_types_count
        ))->implode("\n");
    }
}
