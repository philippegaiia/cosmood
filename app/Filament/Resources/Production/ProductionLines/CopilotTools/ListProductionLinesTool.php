<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionLines\CopilotTools;

use App\Models\Production\ProductionLine;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListProductionLinesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return __('List all production lines with pagination');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Maximum number of production lines to return')
                ->default(10),
            'active_only' => $schema->boolean()
                ->description('Filter to show only active production lines')
                ->default(false),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));
        $activeOnly = $request['active_only'] ?? false;

        $query = ProductionLine::query()->withCount('productTypes');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $lines = $query->orderBy('sort_order')->limit($limit)->get();

        if ($lines->isEmpty()) {
            return __('No production lines were found.');
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
