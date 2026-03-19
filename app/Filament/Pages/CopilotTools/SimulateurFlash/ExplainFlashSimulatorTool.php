<?php

declare(strict_types=1);

namespace App\Filament\Pages\CopilotTools\SimulateurFlash;

use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ExplainFlashSimulatorTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Explain in read-only mode what the flash simulator is for, what it does, and what it does not reserve or execute.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'focus' => $schema->string()->description('Optional focus like batches, needs, conversion to wave, or mistakes')->default('general'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $focus = (string) ($request['focus'] ?? 'general');

        return implode("\n", [
            'Flash simulator summary:',
            '- It is used to estimate production load and material needs quickly.',
            '- It helps compare products, quantities, and batch presets before committing to execution.',
            '- It does not reserve stock, consume inventory, or create real allocations.',
            '- It becomes operational only when the result is converted into a wave or recreated in the real production flow.',
            '- Common mistake: treating a simulation like confirmed execution readiness.',
            '',
            'Current focus: '.$focus,
        ]);
    }
}
