<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supply\SupplyResource\CopilotTools;

use App\Models\Supply\Supply;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewSupplyTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'View a single stock lot in read-only mode by id or batch number.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier' => $schema->string()->description('Supply id or batch number')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $identifier = trim((string) $request['identifier']);

        $supply = Supply::query()
            ->with('supplierListing.ingredient:id,name')
            ->when(is_numeric($identifier), fn ($query) => $query->whereKey((int) $identifier))
            ->when(! is_numeric($identifier), fn ($query) => $query->where('batch_number', $identifier))
            ->first();

        if (! $supply) {
            return "Supply lot '{$identifier}' was not found.";
        }

        return implode("\n", [
            'Batch number: '.$supply->batch_number,
            'Ingredient: '.($supply->supplierListing?->ingredient?->name ?? '-'),
            'Order ref: '.($supply->order_ref ?? '-'),
            'Total quantity: '.number_format($supply->getTotalQuantity(), 3, '.', ''),
            'Available quantity: '.number_format($supply->getAvailableQuantity(), 3, '.', ''),
            'Allocated quantity: '.number_format($supply->getAllocatedQuantity(), 3, '.', ''),
            'In stock: '.($supply->is_in_stock ? 'yes' : 'no'),
        ]);
    }
}
