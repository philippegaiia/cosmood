<?php

declare(strict_types=1);

namespace App\Filament\Resources\QcTemplates\CopilotTools;

use App\Models\Production\QcTemplate;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewQcTemplateTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return __('View detailed information about a specific QC template');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('QC template ID')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $id = $request['id'] ?? null;

        if (! $id) {
            return __('Please provide a QC template ID.');
        }

        $template = QcTemplate::with(['productTypes', 'items'])->find($id);

        if (! $template) {
            return __('QC template not found');
        }

        $productTypes = $template->productTypes->pluck('name')->implode(', ');

        $items = $template->items->map(fn ($item) => sprintf(
            '%s. %s (%s) | Target: %s%s | %s',
            $item->sort_order,
            $item->name,
            $item->type,
            $item->target_value,
            $item->unit ? ' '.$item->unit : '',
            $item->is_required ? 'Required' : 'Optional'
        ))->implode("\n");

        return sprintf(
            "QC Template: %s\nStatus: %s | Default: %s\nProduct Types: %s\n\nItems:\n%s",
            $template->name,
            $template->is_active ? 'Active' : 'Inactive',
            $template->is_default ? 'Yes' : 'No',
            $productTypes ?: __('None'),
            $items ?: __('No items defined')
        );
    }
}
