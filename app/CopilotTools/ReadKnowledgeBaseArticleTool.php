<?php

declare(strict_types=1);

namespace App\CopilotTools;

use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReadKnowledgeBaseArticleTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Read a full user documentation article from the internal knowledge base by slug.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()
                ->description('The documentation slug, for example planning/production-waves or procurement/supplier-orders')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $slug = trim((string) $request['slug']);

        if ($slug === '') {
            return 'Please provide a documentation slug.';
        }

        $article = $this->findArticle($slug);

        if ($article === null) {
            return "No documentation article was found for slug '{$slug}'.";
        }

        return implode("\n", [
            "Title: {$article['title']}",
            "Slug: {$article['slug']}",
            '',
            $article['content'],
        ]);
    }

    /**
     * @return array{slug: string, title: string, content: string}|null
     */
    private function findArticle(string $slug): ?array
    {
        $locales = array_unique([app()->getLocale(), app()->getFallbackLocale()]);

        foreach ($locales as $locale) {
            $path = base_path("docs/knowledge-base/{$locale}/{$slug}.md");

            if (! File::exists($path)) {
                continue;
            }

            $raw = File::get($path);
            $parsed = $this->parseMarkdownFile($raw);

            return [
                'slug' => $slug,
                'title' => $parsed['title'] !== '' ? $parsed['title'] : Str::headline(basename($slug)),
                'content' => $parsed['content'],
            ];
        }

        return null;
    }

    /**
     * @return array{title: string, content: string}
     */
    private function parseMarkdownFile(string $raw): array
    {
        if (! preg_match('/^---\R(.*?)\R---\R(.*)$/s', $raw, $matches)) {
            return [
                'title' => '',
                'content' => trim($raw),
            ];
        }

        $frontMatter = $matches[1];
        $content = trim($matches[2]);
        $title = '';

        foreach (preg_split('/\R/', $frontMatter) ?: [] as $line) {
            if (! str_starts_with($line, 'title:')) {
                continue;
            }

            $title = trim(Str::of($line)->after('title:')->trim()->trim("\"'")->toString());

            break;
        }

        return [
            'title' => $title,
            'content' => $content,
        ];
    }
}
