<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductResource\CopilotTools;

use App\Models\Production\Product;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewProductTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'View a single product in read-only mode by id or code.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier' => $schema->string()->description('Product id or code')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $identifier = trim((string) $request['identifier']);

        $product = Product::query()
            ->with(['productType:id,name', 'productCategory:id,name', 'producedIngredient:id,name'])
            ->when(is_numeric($identifier), fn ($query) => $query->whereKey((int) $identifier))
            ->when(! is_numeric($identifier), fn ($query) => $query->where('code', $identifier))
            ->first();

        if (! $product) {
            return "Product '{$identifier}' was not found.";
        }

        return implode("\n", [
            'Product: '.$product->name,
            'Code: '.$product->code,
            'Category: '.($product->productCategory?->name ?? '-'),
            'Type: '.($product->productType?->name ?? '-'),
            'Net weight: '.number_format((float) $product->net_weight, 3, '.', ''),
            'Launch date: '.(optional($product->launch_date)->format('Y-m-d') ?? '-'),
            'Produced ingredient: '.($product->producedIngredient?->name ?? '-'),
            'Active: '.($product->is_active ? 'yes' : 'no'),
        ]);
    }
}
