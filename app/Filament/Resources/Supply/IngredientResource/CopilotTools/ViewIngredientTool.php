<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supply\IngredientResource\CopilotTools;

use App\Models\Supply\Ingredient;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewIngredientTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'View a single ingredient in read-only mode by id or code.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier' => $schema->string()->description('Ingredient id or code')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $identifier = trim((string) $request['identifier']);

        $ingredient = Ingredient::query()
            ->with('ingredient_category:id,name')
            ->when(is_numeric($identifier), fn ($query) => $query->whereKey((int) $identifier))
            ->when(! is_numeric($identifier), fn ($query) => $query->where('code', $identifier))
            ->first();

        if (! $ingredient) {
            return "Ingredient '{$identifier}' was not found.";
        }

        return implode("\n", [
            'Ingredient: '.$ingredient->name,
            'Code: '.$ingredient->code,
            'Category: '.($ingredient->ingredient_category?->name ?? '-'),
            'Base unit: '.($ingredient->base_unit?->value ?? '-'),
            'Price: '.($ingredient->price ?? '-'),
            'Minimum stock: '.($ingredient->stock_min ?? '-'),
            'Manufactured: '.($ingredient->is_manufactured ? 'yes' : 'no'),
            'Packaging: '.($ingredient->is_packaging ? 'yes' : 'no'),
        ]);
    }
}
