<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supply\SupplierOrderResource\CopilotTools;

use App\Models\Supply\SupplierOrder;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchSupplierOrdersTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search supplier orders by order reference or supplier name in read-only mode.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Order reference or supplier name')->required(),
            'limit' => $schema->integer()->description('Maximum number of results')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request['query']);
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $orders = SupplierOrder::query()
            ->with('supplier:id,name')
            ->where('order_ref', 'like', "%{$query}%")
            ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', "%{$query}%"))
            ->limit($limit)
            ->get();

        if ($orders->isEmpty()) {
            return "No supplier orders matched '{$query}'.";
        }

        return $orders->map(fn (SupplierOrder $order): string => sprintf(
            '#%s | %s | %s | supplier: %s',
            $order->id,
            $order->order_ref,
            $order->order_status->value,
            $order->supplier?->name ?? '-',
        ))->implode("\n");
    }
}
