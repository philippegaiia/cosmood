<?php

declare(strict_types=1);

namespace App\Filament\Resources\QcTemplates\CopilotTools;

use App\Models\Production\QcTemplate;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListQcTemplatesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return __('List all QC templates with pagination');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Maximum number of QC templates to return')
                ->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $templates = QcTemplate::query()
            ->withCount('productTypes')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($templates->isEmpty()) {
            return __('No QC templates were found.');
        }

        return $templates->map(fn (QcTemplate $template): string => sprintf(
            '#%s | %s | %s | %s | %s product types',
            $template->id,
            $template->name,
            $template->is_active ? 'active' : 'inactive',
            $template->is_default ? 'default' : '',
            $template->product_types_count
        ))->implode("\n");
    }
}
