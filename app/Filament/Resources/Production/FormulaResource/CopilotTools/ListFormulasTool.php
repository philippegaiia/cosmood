<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\FormulaResource\CopilotTools;

use App\Models\Production\Formula;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListFormulasTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'List formulas in read-only mode with code, active status, and soap flag.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of formulas to return')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $formulas = Formula::query()
            ->withCount(['formulaItems', 'products'])
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($formulas->isEmpty()) {
            return 'No formulas were found.';
        }

        return $formulas->map(fn (Formula $formula): string => sprintf(
            '#%s | %s | %s | active: %s | soap: %s | items: %d | products: %d',
            $formula->id,
            $formula->code,
            $formula->name,
            $formula->is_active ? 'yes' : 'no',
            $formula->is_soap ? 'yes' : 'no',
            $formula->formula_items_count,
            $formula->products_count,
        ))->implode("\n");
    }
}
