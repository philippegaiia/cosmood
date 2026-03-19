<?php

use App\CopilotTools\ReadKnowledgeBaseArticleTool;
use App\CopilotTools\SearchKnowledgeBaseTool;
use Laravel\Ai\Tools\Request;

it('registers the knowledge base tools globally for copilot', function (): void {
    expect(config('filament-copilot.global_tools'))
        ->toContain(SearchKnowledgeBaseTool::class)
        ->toContain(ReadKnowledgeBaseArticleTool::class);
});

it('searches the knowledge base in the current locale', function (): void {
    app()->setLocale('es');

    $result = app(SearchKnowledgeBaseTool::class)->handle(new Request([
        'query' => 'oleadas de producción',
        'limit' => 3,
    ]));

    expect((string) $result)
        ->toContain('planning/production-waves')
        ->toContain('Oleadas de producción');
});

it('reads a full knowledge base article by slug', function (): void {
    app()->setLocale('es');

    $result = app(ReadKnowledgeBaseArticleTool::class)->handle(new Request([
        'slug' => 'procurement/supplier-orders',
    ]));

    expect((string) $result)
        ->toContain('Title: Pedidos a proveedor')
        ->toContain('El pedido a proveedor sirve para convertir una necesidad en un compromiso real de compra.');
});
