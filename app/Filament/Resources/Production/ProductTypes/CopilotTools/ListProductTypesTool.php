<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductTypes\CopilotTools;

use App\Models\Production\ProductType;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListProductTypesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'List product types in read-only mode with sizing mode, default batch size, and active status.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of product types to return')->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $types = ProductType::query()
            ->with('productCategory:id,name')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($types->isEmpty()) {
            return 'No product types were found.';
        }

        return $types->map(fn (ProductType $type): string => sprintf(
            '#%s | %s | category: %s | sizing: %s | default batch: %s | active: %s',
            $type->id,
            $type->name,
            $type->productCategory?->name ?? '-',
            $type->sizing_mode->value,
            number_format((float) $type->default_batch_size, 3, '.', ''),
            $type->is_active ? 'yes' : 'no',
        ))->implode("\n");
    }
}
