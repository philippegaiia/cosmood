<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductResource\CopilotTools;

use App\Models\Production\Product;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchProductsTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search products by name, code, or EAN in read-only mode.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Product name, code, or EAN')->required(),
            'limit' => $schema->integer()->description('Maximum number of results')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request['query']);
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $products = Product::query()
            ->where('name', 'like', "%{$query}%")
            ->orWhere('code', 'like', "%{$query}%")
            ->orWhere('ean_code', 'like', "%{$query}%")
            ->limit($limit)
            ->get();

        if ($products->isEmpty()) {
            return "No products matched '{$query}'.";
        }

        return $products->map(fn (Product $product): string => sprintf(
            '#%s | %s | %s',
            $product->id,
            $product->code,
            $product->name,
        ))->implode("\n");
    }
}
