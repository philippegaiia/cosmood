<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskTemplates\CopilotTools;

use App\Models\Production\TaskTemplate;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListTaskTemplatesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return __('List all task templates with pagination');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Maximum number of task templates to return')
                ->default(10),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $templates = TaskTemplate::query()
            ->withCount(['productTypes', 'taskTypes'])
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($templates->isEmpty()) {
            return __('No task templates were found.');
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
