<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductTypes\CopilotTools;

use App\Models\Production\ProductType;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchProductTypesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search product types by name or slug in read-only mode.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Product type name or slug')->required(),
            'limit' => $schema->integer()->description('Maximum number of results')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request['query']);
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $types = ProductType::query()
            ->where('name', 'like', "%{$query}%")
            ->orWhere('slug', 'like', "%{$query}%")
            ->limit($limit)
            ->get();

        if ($types->isEmpty()) {
            return "No product types matched '{$query}'.";
        }

        return $types->map(fn (ProductType $type): string => sprintf(
            '#%s | %s | %s',
            $type->id,
            $type->name,
            $type->slug,
        ))->implode("\n");
    }
}
