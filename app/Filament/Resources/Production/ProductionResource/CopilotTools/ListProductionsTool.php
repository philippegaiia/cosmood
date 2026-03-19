<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionResource\CopilotTools;

use App\Models\Production\Production;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListProductionsTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'List production batches in read-only mode with product, status, date, and line.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of productions to return')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $productions = Production::query()
            ->with(['product:id,name', 'productionLine:id,name'])
            ->latest('production_date')
            ->limit($limit)
            ->get();

        if ($productions->isEmpty()) {
            return 'No productions were found.';
        }

        return $productions->map(fn (Production $production): string => sprintf(
            '#%s | %s | %s | %s | %s | %s',
            $production->id,
            $production->batch_number,
            $production->product?->name ?? '-',
            $production->status->value,
            optional($production->production_date)->format('Y-m-d') ?? '-',
            $production->productionLine?->name ?? 'Without line',
        ))->implode("\n");
    }
}
