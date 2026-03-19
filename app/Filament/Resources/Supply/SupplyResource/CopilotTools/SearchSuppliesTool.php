<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supply\SupplyResource\CopilotTools;

use App\Models\Supply\Supply;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchSuppliesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search stock lots by batch number, order reference, or ingredient name in read-only mode.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Batch number, order reference, or ingredient name')->required(),
            'limit' => $schema->integer()->description('Maximum number of results')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request['query']);
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $supplies = Supply::query()
            ->with('supplierListing.ingredient:id,name')
            ->where('batch_number', 'like', "%{$query}%")
            ->orWhere('order_ref', 'like', "%{$query}%")
            ->orWhereHas('supplierListing.ingredient', fn ($ingredientQuery) => $ingredientQuery->where('name', 'like', "%{$query}%"))
            ->limit($limit)
            ->get();

        if ($supplies->isEmpty()) {
            return "No supply lots matched '{$query}'.";
        }

        return $supplies->map(fn (Supply $supply): string => sprintf(
            '#%s | %s | %s',
            $supply->id,
            $supply->batch_number,
            $supply->supplierListing?->ingredient?->name ?? '-',
        ))->implode("\n");
    }
}
