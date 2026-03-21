# Developer Guide: Knowledge Base & Copilot System

This document explains how to maintain and extend the Knowledge Base (KB) and Copilot AI system.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Knowledge Base Maintenance](#knowledge-base-maintenance)
3. [Copilot Tool Development](#copilot-tool-development)
4. [Translation Management](#translation-management)
5. [Testing Guidelines](#testing-guidelines)
6. [Common Patterns](#common-patterns)
7. [Troubleshooting](#troubleshooting)

---

## Architecture Overview

### Two Systems, One Experience

```
┌─────────────────────────────────────────────────────────────┐
│                      Admin Panel                            │
├─────────────────────────────────────────────────────────────┤
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐  │
│  │  Resources   │  │  Contextual  │  │   Copilot AI     │  │
│  │  (Filament)  │  │  Help (? icon)│  │  (Chat + Tools)  │  │
│  └──────────────┘  └──────────────┘  └──────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                              │
              ┌───────────────┴───────────────┐
              │                               │
    ┌─────────▼──────────┐        ┌──────────▼──────────────┐
    │  Knowledge Base    │        │   Copilot Backend       │
    │  (Guava Plugin)    │        │   (Filament Copilot)    │
    │                    │        │                         │
    │  - Markdown docs   │        │  - Gemini API           │
    │  - i18n slugs      │        │  - Tool-based access    │
    │  - Flatfile model  │        │  - Streaming responses  │
    └────────────────────┘        └─────────────────────────┘
```

### Key Design Decisions

1. **Read-Only First**: All Copilot tools are read-only. No create/update/delete tools for safety.
2. **Explicit Exposure**: Copilot cannot explore the app. Every exposed resource/page must implement interfaces and register tools.
3. **Shared Slugs**: Article IDs use English paths (`planning/production-waves`) across all locales.
4. **Workflow-Based Docs**: Docs are organized by user workflow (Planning → Procurement → Execution), not technical models.

---

## Knowledge Base Maintenance

### File Structure

```
docs/knowledge-base/{locale}/
├── getting-started.md           # Parent article
├── getting-started/             # Child articles
│   ├── setup-order.md
│   └── first-production-checklist.md
├── planning.md                  # Parent article
├── planning/                    # Child articles
│   ├── planning-board.md
│   └── flash-simulator.md
```

**Rules**:
- Every folder must have a parent `.md` file with the same name
- Use lowercase with hyphens for filenames
- Nest max 2 levels deep (parent → children)

### Front Matter

Every doc **must** include:

```yaml
---
title: "Localized Title"        # Displayed in navigation and page
order: 1                        # Sort order in sidebar
id: planning/production-waves   # REQUIRED for nested articles!
---
```

**Critical**: The `id:` field must match the path from the locale root using slashes. The plugin converts this internally to dots (`planning.production-waves`), but you must write it with slashes.

### Adding a New Article

1. Create the file:
   ```bash
   touch docs/knowledge-base/fr/planning/production-waves.md
   ```

2. Add front matter:
   ```yaml
   ---
   title: "Vagues de Production"
   order: 1
   id: planning/production-waves
   ---
   ```

3. Write the content in French (master locale)

4. Create translations:
   ```bash
   cp docs/knowledge-base/fr/planning/production-waves.md \
      docs/knowledge-base/en/planning/production-waves.md
   # Edit English version
   cp docs/knowledge-base/fr/planning/production-waves.md \
      docs/knowledge-base/es/planning/production-waves.md
   # Edit Spanish version
   ```

5. Clear cache to see changes immediately:
   ```bash
   php artisan optimize:clear
   ```

### Linking Contextual Help

To add a help button (?) to a Filament resource:

```php
use App	raitsilament	raitsilament	r; // or use HasCopilotDocs for Copilot integration

class ProductionResource extends Resource implements CopilotResource
{
    public static function getDocumentation(): string | null
    {
        return 'execution/productions'; // Matches the `id:` in front matter
    }
}
```

---

## Copilot Tool Development

### Standard Pattern

For each resource that needs Copilot access, create 3 tools:

1. **List Tool**: Paginated overview
2. **Search Tool**: Keyword search across key fields
3. **View Tool**: Single record detail

### Tool Locations

```
app/Filament/Resources/{Resource}/CopilotTools/
├── List{Resources}Tool.php
├── Search{Resources}Tool.php
└── View{Resource}Tool.php
```

### Tool Template

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionResource\CopilotTools;

use App\Models\Production\Production;
use Filament\Copilot\CopilotTool;
use Filament\Copilot\ToolComponents\Contracts\CanUseDatabase;
use Filament\Copilot\ToolComponents\Concerns\InteractsWithDatabase;
use JsonSchema\Schema;

class ListProductionsTool extends CopilotTool implements CanUseDatabase
{
    use InteractsWithDatabase;

    protected static string $name = 'list_productions';

    public static function getSchema(): Schema
    {
        return Schema::object([
            'page' => Schema::integer()
                ->default(1)
                ->description('Page number for pagination'),
            'per_page' => Schema::integer()
                ->default(10)
                ->description('Number of results per page (max 50)'),
        ]);
    }

    public function execute(array $arguments): string
    {
        $page = $arguments['page'] ?? 1;
        $perPage = min($arguments['per_page'] ?? 10, 50);

        $productions = Production::query()
            ->select(['id', 'batch_number', 'name', 'status', 'production_date'])
            ->orderBy('production_date', 'desc')
            ->paginate($perPage, page: $page);

        return $this->formatPaginatedResults($productions, [
            'id',
            'batch_number',
            'name',
            'status',
            'production_date',
        ]);
    }

    public static function getDescription(): string
    {
        return __('List all productions with pagination');
    }
}
```

### Registering Tools

Add to the resource class:

```php
use App\Filament\Resources\Production\ProductionResource\CopilotTools;
use App\traits\filament\traits\filament\tr;

class ProductionResource extends Resource implements CopilotResource
{
    use CopilotResourceTrait;

    public static function copilotTools(): array
    {
        return [
            CopilotTools\ListProductionsTool::class,
            CopilotTools\SearchProductionsTool::class,
            CopilotTools\ViewProductionTool::class,
        ];
    }

    public static function copilotResourceDescription(): string
    {
        return __('Productions are manufacturing jobs with batch numbers, dates, and tasks');
    }
}
```

### Quick Generation

Use the artisan command:

```bash
php artisan make:copilot-tool

# Follow the interactive prompts:
# - Tool name: ListProductionsTool
# - Resource class: ProductionResource
# - Model class: Production
# - Type: ListTool
```

### Tool Types

| Type | Purpose | Schema | Example |
|------|---------|--------|---------|
| ListTool | Paginated overview | `page`, `per_page` | ListProductionsTool |
| SearchTool | Keyword search | `query`, `page`, `per_page` | SearchProductsTool |
| ViewTool | Single record detail | `id` | ViewFormulaTool |
| SummaryTool | Complex aggregation | `limit`, `filters` | GetPlanningBoardSummaryTool |

---

## Translation Management

### Location Reference

| Translation Type | Location | Example |
|------------------|----------|---------|
| UI Strings | `lang/{locale}.json` | `{"Hello": "Bonjour"}` |
| Resource Labels | `lang/{locale}/resources.php` | `return ['product' => 'Produit'];` |
| Navigation | `lang/{locale}/navigation.php` | `return ['planning' => 'Planification'];` |
| KB UI | `lang/vendor/filament-knowledge-base/{locale}/translations.php` | Plugin UI strings |
| Copilot UI | `lang/vendor/filament-copilot/{locale}/filament-copilot.php` | Chat interface strings |

### Adding a New UI String

**For JSON files** (simple strings):

```bash
# Add to lang/fr.json, lang/en.json, lang/es.json
{
  "Welcome to": "Bienvenue dans",
  "Production Manager": "Gestionnaire de Production"
}
```

**For PHP files** (structured/nested strings):

```php
// lang/fr/resources.php
return [
    'product' => [
        'label' => 'Produit',
        'description' => 'Un produit fini',
    ],
];

// Usage: __('resources.product.label')
```

### Translation Workflow

1. **Add to master locale first** (French)
2. **Copy structure** to other locales
3. **Translate values** only, keep keys identical
4. **Test all locales** before committing

```bash
# Quick check in tinker
php artisan tinker
>>> app()->setLocale('fr'); dump(__('Your Key'));
>>> app()->setLocale('en'); dump(__('Your Key'));
```

---

## Testing Guidelines

### Test Structure

```
tests/Feature/Copilot/
├── KnowledgeBaseToolsTest.php          # Global KB search
├── OperationalReadOnlyToolsTest.php    # Planning, Waves, Orders, Productions
├── ExtendedReadOnlyToolsTest.php       # Flash, Procurement, Ingredients, Supplies
└── ProductMasterDataReadOnlyToolsTest.php # Types, Products, Formulas
```

### Running Tests

```bash
# All Copilot tests
php artisan test --compact tests/Feature/Copilot/

# Specific tool suite
php artisan test --compact tests/Feature/Copilot/OperationalReadOnlyToolsTest.php

# Specific test method
php artisan test --compact --filter=test_list_productions_tool_returns_paginated_results
```

### Test Template

```php
<?php

use App\Filament\Resources\Production\ProductionResource\CopilotTools\ListProductionsTool;
use App\Models\Production\Production;

it('returns paginated list of productions', function () {
    Production::factory()->count(5)->create();

    $tool = new ListProductionsTool();
    $result = $tool->execute(['page' => 1, 'per_page' => 3]);

    expect($result)
        ->toBeString()
        ->toContain('batch_number')
        ->toContain('status');
});

it('respects pagination limits', function () {
    Production::factory()->count(10)->create();

    $tool = new ListProductionsTool();
    $result = $tool->execute(['page' => 1, 'per_page' => 100]);

    // Should be capped at 50
    expect($result)->toBeString();
});
```

---

## Common Patterns

### Eager Loading in Tools

Always eager load relationships to avoid N+1:

```php
$records = Production::with(['product', 'formula', 'tasks'])
    ->select([...])
    ->paginate($perPage);
```

### Complex Queries (Warehouse Tools)

For tools that query multiple models:

```php
public function execute(array $arguments): string
{
    $user = auth()->user();
    $filters = $arguments['filters'] ?? [];

    $productions = Production::query()
        ->forUser($user)
        ->applyFilters($filters)
        ->with(['product', 'status'])
        ->get();

    return $this->formatResults($productions);
}
```

### Custom Description by Context

```php
public static function getDescription(): string
{
    return match(app()->getLocale()) {
        'fr' => 'Liste les productions avec pagination',
        'en' => 'List all productions with pagination',
        'es' => 'Listar todas las producciones con paginación',
        default => 'List productions',
    };
}
```

### Dynamic Schema Based on User

```php
public static function getSchema(): Schema
{
    $user = auth()->user();
    
    return Schema::object([
        'product_type' => Schema::string()
            ->enum($user->accessibleProductTypes()->pluck('name')->toArray())
            ->description('Filter by product type'),
    ]);
}
```

---

## Troubleshooting

### "Article not found" in Help Menu

**Problem**: Help button returns 404 or empty page.

**Solution**:
1. Check the `id:` in front matter matches `getDocumentation()` return value
2. Verify file exists in all locales
3. Clear cache: `php artisan optimize:clear`
4. Check logs: `storage/logs/laravel.log`

### Copilot Says "I don't have access to that"

**Problem**: LLM claims it can't access data.

**Solution**:
1. Verify resource implements `CopilotResource` interface
2. Check `copilotTools()` returns the tool class
3. Ensure tool class is in correct namespace
4. Check tool is registered in `config/filament-copilot.php`

### Translations Not Loading

**Problem**: UI shows translation keys instead of values.

**Solution**:
```bash
php artisan optimize:clear
php artisan cache:clear

# Check file exists
ls -la lang/vendor/filament-copilot/fr/filament-copilot.php

# Verify structure
cat lang/vendor/filament-copilot/fr/filament-copilot.php | head -20
```

### Tools Not Appearing in Conversation

**Problem**: LLM doesn't invoke tools.

**Solution**:
1. Check tool schema is valid JSON Schema
2. Verify tool name is unique (no duplicates)
3. Ensure `getDescription()` is helpful
4. Check `execute()` doesn't throw exceptions
5. Review tool registration in resource

### Performance Issues

**Problem**: Copilot responses are slow.

**Solution**:
- Add `limit` to all list/search tools (default: 10, max: 50)
- Eager load relationships in tools
- Use `select()` to fetch only needed columns
- Consider caching expensive aggregations
- Use database indexes on search fields

---

## Quick Reference

### Commands

```bash
# Generate tool
php artisan make:copilot-tool

# Clear all caches
php artisan optimize:clear

# Run Copilot tests
php artisan test --compact tests/Feature/Copilot/

# Check current locale
tinker --execute="dump(app()->getLocale());"

# List all tools
php artisan copilot:tools

# Validate KB structure
php artisan knowledge-base:validate
```

### File Templates

**New Article**:
```markdown
---
title: "Article Title"
order: 1
id: section/article-id
---

# Article Title

Content goes here...
```

**New Tool**:
```php
<?php
declare(strict_types=1);

namespace App\Filament\Resources\{Resource}\CopilotTools;

use Filament\Copilot\CopilotTool;
use JsonSchema\Schema;

class {Action}{Resource}Tool extends CopilotTool
{
    protected static string $name = '{action}_{resource}';

    public static function getSchema(): Schema
    {
        return Schema::object([...]);
    }

    public function execute(array $arguments): string
    {
        // Implementation
    }

    public static function getDescription(): string
    {
        return __('Description here');
    }
}
```

---

## Contact

For questions or issues with this system:
1. Check this documentation first
2. Review existing tool implementations in `app/Filament/Resources/`
3. Check the test suite for usage examples
4. Consult the Knowledge Base at `/kb` for user-facing documentation