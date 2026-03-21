<?php

declare(strict_types=1);

namespace App\Filament\Resources\QcTemplates\CopilotTools;

use App\Models\Production\QcTemplate;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchQcTemplatesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return __('Search QC templates by name');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search term for QC template name')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of results to return')
                ->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = $request['query'] ?? '';
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        if (empty($query)) {
            return __('Please provide a search query.');
        }

        $templates = QcTemplate::query()
            ->withCount('productTypes')
            ->where('name', 'like', "%{$query}%")
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($templates->isEmpty()) {
            return __('No QC templates found matching your search.');
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
