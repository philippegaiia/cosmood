<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductResource\CopilotTools;

use App\Models\Production\Product;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListProductsTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'List products in read-only mode with type, code, and active status.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of products to return')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $products = Product::query()
            ->with(['productType:id,name', 'productCategory:id,name'])
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($products->isEmpty()) {
            return 'No products were found.';
        }

        return $products->map(fn (Product $product): string => sprintf(
            '#%s | %s | %s | type: %s | category: %s | active: %s',
            $product->id,
            $product->code,
            $product->name,
            $product->productType?->name ?? '-',
            $product->productCategory?->name ?? '-',
            $product->is_active ? 'yes' : 'no',
        ))->implode("\n");
    }
}
