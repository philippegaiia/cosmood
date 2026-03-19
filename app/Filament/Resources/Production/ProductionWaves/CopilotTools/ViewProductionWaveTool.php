<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionWaves\CopilotTools;

use App\Models\Production\ProductionWave;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewProductionWaveTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'View a single production wave in read-only mode by id or slug.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier' => $schema->string()->description('Wave id or slug')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $identifier = trim((string) $request['identifier']);

        $wave = ProductionWave::query()
            ->withCount('productions')
            ->when(is_numeric($identifier), fn ($query) => $query->whereKey((int) $identifier))
            ->when(! is_numeric($identifier), fn ($query) => $query->where('slug', $identifier))
            ->first();

        if (! $wave) {
            return "Production wave '{$identifier}' was not found.";
        }

        return implode("\n", [
            "Wave: {$wave->name}",
            "Slug: {$wave->slug}",
            "Status: {$wave->status->value}",
            'Productions: '.$wave->productions_count,
            'Start date: '.(optional($wave->planned_start_date)->format('Y-m-d') ?? '-'),
            'End date: '.(optional($wave->planned_end_date)->format('Y-m-d') ?? '-'),
            'Notes: '.($wave->notes ?: '-'),
        ]);
    }
}
