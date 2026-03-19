<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionResource\CopilotTools;

use App\Models\Production\Production;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewProductionTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'View a single production batch in read-only mode by id or batch number.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier' => $schema->string()->description('Production id or batch number')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $identifier = trim((string) $request['identifier']);

        $production = Production::query()
            ->with(['product:id,name', 'productionLine:id,name', 'wave:id,name'])
            ->when(is_numeric($identifier), fn ($query) => $query->whereKey((int) $identifier))
            ->when(! is_numeric($identifier), fn ($query) => $query->where('batch_number', $identifier))
            ->first();

        if (! $production) {
            return "Production '{$identifier}' was not found.";
        }

        return implode("\n", [
            "Batch: {$production->batch_number}",
            'Status: '.$production->status->value,
            'Product: '.($production->product?->name ?? '-'),
            'Wave: '.($production->wave?->name ?? '-'),
            'Line: '.($production->productionLine?->name ?? 'Without line'),
            'Production date: '.(optional($production->production_date)->format('Y-m-d') ?? '-'),
            'Ready date: '.(optional($production->ready_date)->format('Y-m-d') ?? '-'),
            'Supply coverage: '.$production->getSupplyCoverageLabel(),
        ]);
    }
}
