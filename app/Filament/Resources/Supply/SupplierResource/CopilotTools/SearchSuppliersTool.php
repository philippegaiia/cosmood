<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supply\SupplierResource\CopilotTools;

use App\Models\Supply\Supplier;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchSuppliersTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return __('Search suppliers by name or code');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search term for supplier name or code')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of results to return')
                ->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = $request['query'] ?? '';
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        if (empty($query)) {
            return __('Please provide a search query.');
        }

        $suppliers = Supplier::query()
            ->withCount(['contacts', 'supplier_listings'])
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('code', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($suppliers->isEmpty()) {
            return __('No suppliers found matching your search.');
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
