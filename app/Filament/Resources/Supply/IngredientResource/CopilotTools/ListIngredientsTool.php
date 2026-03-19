<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supply\IngredientResource\CopilotTools;

use App\Models\Supply\Ingredient;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListIngredientsTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'List ingredients in read-only mode with code, category, and stock alert data.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of ingredients to return')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $ingredients = Ingredient::query()
            ->with('ingredient_category:id,name')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($ingredients->isEmpty()) {
            return 'No ingredients were found.';
        }

        return $ingredients->map(fn (Ingredient $ingredient): string => sprintf(
            '#%s | %s | %s | category: %s | min stock: %s',
            $ingredient->id,
            $ingredient->code,
            $ingredient->name,
            $ingredient->ingredient_category?->name ?? '-',
            number_format((float) $ingredient->stock_min, 3, '.', ''),
        ))->implode("\n");
    }
}
