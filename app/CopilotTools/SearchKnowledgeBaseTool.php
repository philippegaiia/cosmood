<?php

declare(strict_types=1);

namespace App\CopilotTools;

use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchKnowledgeBaseTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search the internal user documentation and return the most relevant articles, common mistakes, and how-to guidance for operators and planners.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('What the user wants to understand, such as a page, button, workflow, or mistake to avoid')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of articles to return')
                ->default(5),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request['query']);
        $limit = max(1, min(10, (int) ($request['limit'] ?? 5)));

        if ($query === '') {
            return 'Please provide a search query.';
        }

        $matches = collect($this->getArticles())
            ->map(fn (array $article): array => [
                ...$article,
                'score' => $this->scoreArticle($article, $query),
                'excerpt' => $this->makeExcerpt($article['content'], $query),
            ])
            ->filter(fn (array $article): bool => $article['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        if ($matches->isEmpty()) {
            return "No documentation articles matched '{$query}'. Try broader keywords like production, wave, supplier order, stock, allocation, or QC.";
        }

        $lines = [
            "Knowledge base matches for: {$query}",
            '',
        ];

        foreach ($matches as $article) {
            $lines[] = "- {$article['title']} [slug: {$article['slug']}]";
            $lines[] = "  {$article['excerpt']}";
        }

        $lines[] = '';
        $lines[] = 'Use read_knowledge_base_article with a slug if you need the full article before answering.';

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array{slug: string, title: string, content: string}>
     */
    private function getArticles(): array
    {
        $locale = app()->getLocale();
        $fallbackLocale = app()->getFallbackLocale();

        $path = base_path("docs/knowledge-base/{$locale}");

        if (! File::exists($path)) {
            $path = base_path("docs/knowledge-base/{$fallbackLocale}");
        }

        if (! File::exists($path)) {
            return [];
        }

        return collect(File::allFiles($path))
            ->filter(fn ($file): bool => in_array($file->getExtension(), ['md', 'markdown'], true))
            ->map(function ($file) use ($path): array {
                $raw = File::get($file->getRealPath());
                $parsed = $this->parseMarkdownFile($raw);

                return [
                    'slug' => str($file->getRealPath())
                        ->after($path.DIRECTORY_SEPARATOR)
                        ->beforeLast('.'.$file->getExtension())
                        ->replace(DIRECTORY_SEPARATOR, '/')
                        ->toString(),
                    'title' => $parsed['title'] ?: Str::headline($file->getFilenameWithoutExtension()),
                    'content' => $parsed['content'],
                ];
            })
            ->all();
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

    private function scoreArticle(array $article, string $query): int
    {
        $haystackTitle = Str::lower($article['title']);
        $haystackContent = Str::lower($article['content']);
        $terms = collect(preg_split('/\s+/', Str::lower($query)) ?: [])->filter();

        $score = 0;

        foreach ($terms as $term) {
            if (str_contains($haystackTitle, $term)) {
                $score += 8;
            }

            if (str_contains($haystackContent, $term)) {
                $score += 3;
            }
        }

        if (str_contains($haystackTitle, Str::lower($query))) {
            $score += 10;
        }

        if (str_contains($haystackContent, Str::lower($query))) {
            $score += 5;
        }

        return $score;
    }

    private function makeExcerpt(string $content, string $query): string
    {
        $normalizedContent = preg_replace('/\s+/', ' ', strip_tags($content)) ?? $content;
        $lower = Str::lower($normalizedContent);
        $needle = Str::lower($query);
        $position = strpos($lower, $needle);

        if ($position === false) {
            return Str::limit(trim($normalizedContent), 180);
        }

        $start = max(0, $position - 70);
        $excerpt = substr($normalizedContent, $start, 180);

        return trim(($start > 0 ? '... ' : '').$excerpt.' ...');
    }
}
