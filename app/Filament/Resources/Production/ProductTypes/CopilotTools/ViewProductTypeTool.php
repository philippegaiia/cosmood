<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductTypes\CopilotTools;

use App\Models\Production\ProductType;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewProductTypeTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'View a single product type in read-only mode by id or slug.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier' => $schema->string()->description('Product type id or slug')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $identifier = trim((string) $request['identifier']);

        $type = ProductType::query()
            ->with(['productCategory:id,name', 'defaultProductionLine:id,name'])
            ->when(is_numeric($identifier), fn ($query) => $query->whereKey((int) $identifier))
            ->when(! is_numeric($identifier), fn ($query) => $query->where('slug', $identifier))
            ->first();

        if (! $type) {
            return "Product type '{$identifier}' was not found.";
        }

        return implode("\n", [
            'Product type: '.$type->name,
            'Slug: '.$type->slug,
            'Category: '.($type->productCategory?->name ?? '-'),
            'Sizing mode: '.$type->sizing_mode->value,
            'Default batch size: '.number_format((float) $type->default_batch_size, 3, '.', ''),
            'Expected units: '.(string) $type->expected_units_output,
            'Default line: '.($type->defaultProductionLine?->name ?? '-'),
            'Active: '.($type->is_active ? 'yes' : 'no'),
        ]);
    }
}
