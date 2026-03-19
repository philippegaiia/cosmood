<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supply\SupplierOrderResource\CopilotTools;

use App\Models\Supply\SupplierOrder;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewSupplierOrderTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'View a single supplier order in read-only mode by id or order reference.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier' => $schema->string()->description('Supplier order id or order reference')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $identifier = trim((string) $request['identifier']);

        $order = SupplierOrder::query()
            ->with(['supplier:id,name', 'wave:id,name', 'supplier_order_items'])
            ->when(is_numeric($identifier), fn ($query) => $query->whereKey((int) $identifier))
            ->when(! is_numeric($identifier), fn ($query) => $query->where('order_ref', $identifier))
            ->first();

        if (! $order) {
            return "Supplier order '{$identifier}' was not found.";
        }

        return implode("\n", [
            "Order: {$order->order_ref}",
            "Status: {$order->order_status->value}",
            'Supplier: '.($order->supplier?->name ?? '-'),
            'Wave: '.($order->wave?->name ?? '-'),
            'Order date: '.(optional($order->order_date)->format('Y-m-d') ?? '-'),
            'Delivery date: '.(optional($order->delivery_date)->format('Y-m-d') ?? '-'),
            'Items: '.$order->supplier_order_items->count(),
        ]);
    }
}
