<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\FormulaResource\CopilotTools;

use App\Models\Production\Formula;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchFormulasTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search formulas by name, code, or DIP number in read-only mode.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Formula name, code, or DIP number')->required(),
            'limit' => $schema->integer()->description('Maximum number of results')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request['query']);
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $formulas = Formula::query()
            ->where('name', 'like', "%{$query}%")
            ->orWhere('code', 'like', "%{$query}%")
            ->orWhere('dip_number', 'like', "%{$query}%")
            ->limit($limit)
            ->get();

        if ($formulas->isEmpty()) {
            return "No formulas matched '{$query}'.";
        }

        return $formulas->map(fn (Formula $formula): string => sprintf(
            '#%s | %s | %s',
            $formula->id,
            $formula->code,
            $formula->name,
        ))->implode("\n");
    }
}
