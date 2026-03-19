<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supply\IngredientResource\CopilotTools;

use App\Models\Supply\Ingredient;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchIngredientsTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search ingredients by name, code, or INCI in read-only mode.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Ingredient name, code, or INCI')->required(),
            'limit' => $schema->integer()->description('Maximum number of results')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request['query']);
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $ingredients = Ingredient::query()
            ->where('name', 'like', "%{$query}%")
            ->orWhere('code', 'like', "%{$query}%")
            ->orWhere('inci', 'like', "%{$query}%")
            ->limit($limit)
            ->get();

        if ($ingredients->isEmpty()) {
            return "No ingredients matched '{$query}'.";
        }

        return $ingredients->map(fn (Ingredient $ingredient): string => sprintf(
            '#%s | %s | %s',
            $ingredient->id,
            $ingredient->code,
            $ingredient->name,
        ))->implode("\n");
    }
}
