<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supply\SupplyResource\CopilotTools;

use App\Models\Supply\Supply;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListSuppliesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'List stock lots in read-only mode with batch number, ingredient, and available quantity.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of lots to return')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $supplies = Supply::query()
            ->with('supplierListing.ingredient:id,name')
            ->orderByDesc('delivery_date')
            ->limit($limit)
            ->get();

        if ($supplies->isEmpty()) {
            return 'No supply lots were found.';
        }

        return $supplies->map(fn (Supply $supply): string => sprintf(
            '#%s | %s | %s | available: %s | in stock: %s',
            $supply->id,
            $supply->batch_number,
            $supply->supplierListing?->ingredient?->name ?? '-',
            number_format($supply->getAvailableQuantity(), 3, '.', ''),
            $supply->is_in_stock ? 'yes' : 'no',
        ))->implode("\n");
    }
}
