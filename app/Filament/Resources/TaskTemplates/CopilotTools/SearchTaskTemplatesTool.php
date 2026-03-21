<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskTemplates\CopilotTools;

use App\Models\Production\TaskTemplate;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchTaskTemplatesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return __('Search task templates by name');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search term for template name')
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

        $templates = TaskTemplate::query()
            ->withCount(['productTypes', 'taskTypes'])
            ->where('name', 'like', "%{$query}%")
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($templates->isEmpty()) {
            return __('No task templates found matching your search.');
        }

        return $templates->map(fn (TaskTemplate $template): string => sprintf(
            '#%s | %s | %s product types | %s task types',
            $template->id,
            $template->name,
            $template->product_types_count,
            $template->task_types_count
        ))->implode("\n");
    }
}
