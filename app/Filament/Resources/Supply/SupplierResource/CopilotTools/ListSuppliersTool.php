<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supply\SupplierResource\CopilotTools;

use App\Models\Supply\Supplier;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListSuppliersTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return __('List all suppliers with pagination');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Maximum number of suppliers to return')
                ->default(10),
            'active_only' => $schema->boolean()
                ->description('Filter to show only active suppliers')
                ->default(false),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));
        $activeOnly = $request['active_only'] ?? false;

        $query = Supplier::query()->withCount(['contacts', 'supplier_listings']);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $suppliers = $query->orderBy('name')->limit($limit)->get();

        if ($suppliers->isEmpty()) {
            return __('No suppliers were found.');
        }

        return $suppliers->map(fn (Supplier $supplier): string => sprintf(
            '#%s | %s | %s | %s | %s days delivery | %s contacts | %s listings',
            $supplier->id,
            $supplier->name,
            $supplier->code,
            $supplier->is_active ? 'active' : 'inactive',
            $supplier->estimated_delivery_days,
            $supplier->contacts_count,
            $supplier->supplier_listings_count
        ))->implode("\n");
    }
}
