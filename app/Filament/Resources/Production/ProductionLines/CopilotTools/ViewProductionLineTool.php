<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionLines\CopilotTools;

use App\Models\Production\ProductionLine;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewProductionLineTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return __('View detailed information about a specific production line');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('Production line ID')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $id = $request['id'] ?? null;

        if (! $id) {
            return __('Please provide a production line ID.');
        }

        $line = ProductionLine::with([
            'productTypes',
            'productions' => fn ($q) => $q->latest()->limit(5),
        ])->find($id);

        if (! $line) {
            return __('Production line not found');
        }

        $productTypes = $line->productTypes->pluck('name')->implode(', ');

        $recentProductions = $line->productions->map(fn ($prod) => sprintf(
            '%s | %s | %s | %s',
            $prod->batch_number,
            $prod->name,
            $prod->status->value,
            optional($prod->production_date)->format('Y-m-d') ?? '-'
        ))->implode("\n");

        return sprintf(
            "Production Line: %s\nDaily Capacity: %s batches\nStatus: %s\nProduct Types: %s\n\nRecent Productions:\n%s",
            $line->name,
            $line->daily_batch_capacity,
            $line->is_active ? 'Active' : 'Inactive',
            $productTypes ?: __('None assigned'),
            $recentProductions ?: __('No recent productions')
        );
    }
}
