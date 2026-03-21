<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskTemplates\CopilotTools;

use App\Models\Production\TaskTemplate;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewTaskTemplateTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return __('View detailed information about a specific task template');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('Task template ID')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $id = $request['id'] ?? null;

        if (! $id) {
            return __('Please provide a task template ID.');
        }

        $template = TaskTemplate::with([
            'productTypes',
            'taskTemplateTaskTypes.taskType',
        ])->find($id);

        if (! $template) {
            return __('Task template not found');
        }

        $productTypes = $template->productTypes->map(fn ($pt) => sprintf('%s%s', $pt->name, ($pt->pivot->is_default ?? false) ? ' (default)' : '')
        )->implode(', ');

        $tasks = $template->taskTemplateTaskTypes->map(fn ($pivot) => sprintf(
            '%s. %s (day %+d%s)',
            $pivot->sort_order,
            $pivot->taskType?->name ?? 'Unknown',
            $pivot->offset_days,
            $pivot->skip_weekends ? ', skip weekends' : ''
        ))->implode("\n");

        return sprintf(
            "Task Template: %s\nProduct Types: %s\n\nTasks:\n%s",
            $template->name,
            $productTypes ?: __('None'),
            $tasks ?: __('No tasks defined')
        );
    }
}
