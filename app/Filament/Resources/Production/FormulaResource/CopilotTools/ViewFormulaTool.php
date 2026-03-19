<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\FormulaResource\CopilotTools;

use App\Models\Production\Formula;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewFormulaTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'View a single formula in read-only mode by id or code.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier' => $schema->string()->description('Formula id or code')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $identifier = trim((string) $request['identifier']);

        $formula = Formula::query()
            ->withCount(['formulaItems', 'products', 'productions'])
            ->when(is_numeric($identifier), fn ($query) => $query->whereKey((int) $identifier))
            ->when(! is_numeric($identifier), fn ($query) => $query->where('code', $identifier))
            ->first();

        if (! $formula) {
            return "Formula '{$identifier}' was not found.";
        }

        return implode("\n", [
            'Formula: '.$formula->name,
            'Code: '.$formula->code,
            'DIP: '.($formula->dip_number ?: '-'),
            'Active: '.($formula->is_active ? 'yes' : 'no'),
            'Soap formula: '.($formula->is_soap ? 'yes' : 'no'),
            'Formula items: '.(string) $formula->formula_items_count,
            'Linked products: '.(string) $formula->products_count,
            'Productions: '.(string) $formula->productions_count,
        ]);
    }
}
