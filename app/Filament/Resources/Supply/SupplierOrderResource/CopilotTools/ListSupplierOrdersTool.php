<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supply\SupplierOrderResource\CopilotTools;

use App\Models\Supply\SupplierOrder;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListSupplierOrdersTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'List supplier orders in read-only mode with supplier, status, and delivery date.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of orders to return')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $orders = SupplierOrder::query()
            ->with(['supplier:id,name', 'wave:id,name'])
            ->latest('id')
            ->limit($limit)
            ->get();

        if ($orders->isEmpty()) {
            return 'No supplier orders were found.';
        }

        return $orders->map(fn (SupplierOrder $order): string => sprintf(
            '#%s | %s | %s | supplier: %s | delivery: %s | wave: %s',
            $order->id,
            $order->order_ref,
            $order->order_status->value,
            $order->supplier?->name ?? '-',
            optional($order->delivery_date)->format('Y-m-d') ?? '-',
            $order->wave?->name ?? '-',
        ))->implode("\n");
    }
}
