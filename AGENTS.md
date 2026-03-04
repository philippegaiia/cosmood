<laravel-boost-guidelines>
=== foundation rules ===

# Project Memory (Read First Each Session)

Before making changes, read these project docs to restore business context:

- `docs/spec-production-workflows.md`
- `docs/spec-flash-simulator.md`
- `docs/seeding-strategy.md`

When a change modifies these workflows, update the matching doc in the same branch.

Code commenting expectations for critical logic:

- Add/maintain concise PHPDoc in core service logic and observers, especially in:
  - `app/Services/Production/FlashSimulationService.php`
  - `app/Services/Production/ProductionQcGenerationService.php`
  - `app/Services/Production/PermanentBatchNumberService.php`
  - `app/Observers/ProductionObserver.php`
- Avoid noisy inline comments; document intent and invariants where a future developer could break business rules.

## Internationalization (i18n) Guidelines

**ALWAYS use translation functions** for all user-facing strings to ensure the application can be translated in the future:

```php
// In PHP code (Filament forms, tables, validation messages)
->label(__('Date'))
->helperText(__('Laisser vide pour utiliser la valeur par défaut'))

// In Blade templates
<h1>{{ __('Welcome') }}</h1>

// With parameters
__('Hello :name', ['name' => $user->name])
```

**Current convention**: Strings are in French but wrapped in `__()` for future translation support.

## Holidays Management System

The application includes a holidays management system for production scheduling:

### Database
- Table: `holidays` (see migration)
- Model: `App\Models\Production\Holiday`
- Fields: `date`, `name`, `is_recurring`, `year`

### Features
- **Recurring holidays**: Annual holidays like Christmas (stored once, applied every year)
- **Specific holidays**: One-time holidays for a specific date
- **Filament Resource**: `HolidayResource` under Settings navigation group

### Scheduling Integration
Task scheduling automatically skips holidays when calculating production dates:
- Location: `TaskGenerationService::calculateScheduledDate()`
- Holidays are checked after weekend checks
- Method: `Holiday::isHoliday(Carbon $date)`

### Usage Example
```php
// Check if a date is a holiday
if (Holiday::isHoliday($date)) {
    // Skip this date for scheduling
}

// Get all holidays in a date range
$holidays = Holiday::getHolidayDatesBetween($startDate, $endDate);
```

## Production Date Changes

When a production's `production_date` is changed, tasks are automatically rescheduled:

### Trigger
- Location: `ProductionObserver::updated()` at line 70-72
- Condition: `production_date` changed AND status is `Planned`, `Confirmed`, or `Ongoing`

### Behavior
```php
// In ProductionObserver
if ($production->wasChanged('production_date') && in_array($production->status, self::RESCHEDULABLE_STATUSES, true)) {
    $this->taskGenerationService->rescheduleTasks($production);
}
```

### rescheduleTasks() Logic
Location: `TaskGenerationService::rescheduleTasks()` at line 91-167

1. **Recalculates dates** based on new `production_date` + `offset_days` from task template pivot
2. **Skips weekends** if `skip_weekends` is true in pivot data
3. **Skips holidays** using `Holiday::isHoliday()` check
4. **Respects dependencies** - won't schedule a task before the previous task's scheduled date
5. **Preserves manual schedules** - tasks with `is_manual_schedule=true` keep their dates (unless `$force=true`)
6. **Ignores finished/cancelled tasks** - only reschedules pending tasks

### Important Notes
- Tasks linked to a `production_task_type_id` (from master list) are rescheduled
- Tasks without a task type ID are skipped
- The `date` field is updated along with `scheduled_date`
- Manual schedule flag is cleared when auto-rescheduled

## Lazy Loading Prevention

This application has lazy loading prevention enabled in **non-production environments** to catch N+1 query issues early:

**Configuration:** `AppServiceProvider.php:16`
```php
Model::preventLazyLoading(! app()->isProduction());
```

### What This Means
- **Development/Local:** Accessing unloaded relationships throws `LazyLoadingViolationException`
- **Production:** Lazy loading is allowed (but you should still avoid it for performance)

### How to Fix Lazy Loading Violations

**Always eager load relationships using `with()`:**

```php
// ❌ BAD - Will throw exception in development
$template->taskTemplateTaskTypes->map(fn ($pivot) => $pivot->taskType->name);

// ✅ GOOD - Eager load nested relationships
$template->load('taskTemplateTaskTypes.taskType');
$template->taskTemplateTaskTypes->map(fn ($pivot) => $pivot->taskType->name);

// ✅ ALSO GOOD - Eager load at query time
TaskTemplate::with('taskTemplateTaskTypes.taskType')->first();
```

### Common Patterns

**Services returning models:**
```php
public function getTaskTemplateForProduction(Production $production): ?TaskTemplate
{
    return TaskTemplate::with('taskTemplateTaskTypes.taskType')->first();
}
```

**Defensive loading in methods:**
```php
public function processTemplate(TaskTemplate $template): void
{
    // Ensure relationships are loaded (idempotent - safe to call multiple times)
    $template->loadMissing('taskTemplateTaskTypes.taskType');
    
    // Now safe to access nested relationships
    foreach ($template->taskTemplateTaskTypes as $pivot) {
        $name = $pivot->taskType->name;
    }
}
```

### Key Locations
- `TaskGenerationService::getTaskTemplateForProduction()` - Eager loads template relationships
- `TaskGenerationService::generateFromTemplate()` - Defensively loads relationships
- `TaskGenerationService::rescheduleTasks()` - Defensively loads relationships

### Related
- Always check query logs with `DB::enableQueryLog()` and `DB::getQueryLog()` to verify eager loading
- Use `->relationLoaded('relationship')` to check if already loaded
- Use `->loadMissing('relationship')` for conditional loading

## Out-of-Stock Supply Management

Supplies can be marked as "out of stock" to exclude them from calculations while preserving historical traceability.

### Key Fields
- `supplies.is_in_stock` (boolean) - Controls inclusion in calculations
- `supplies.last_used_at` (timestamp) - Auto-updated when consumed

### Workflow
1. Supply empty → Manual adjustment with comment (set qty to 0)
2. Mark as out-of-stock → "Marquer épuisé" action
3. Supply excluded from:
   - Ingredient stock totals
   - Available supply listings
   - Allocation dropdowns

### Stock Calculation Logic
- **Physical Stock**: Sum of `(quantity_in - quantity_out)` for in-stock supplies only
- **Allocated Stock**: Sum of allocation movements from in-stock supplies
- **Available Stock**: Physical - Allocated (both filtered by `is_in_stock = true`)

### Double-Entry Accounting
- **Allocation**: `+quantity` (allocation movement type)
- **Release**: `-quantity` (allocation movement type) - compensating transaction
- **Consumption**: `-quantity` (allocation) + `+quantity` (outbound) - two movements

### Database Index
```sql
CREATE INDEX idx_supply_movement_type ON supplies_movements(supply_id, movement_type);
```

### Key Locations
- `Supply::getAllocatedQuantity()` - Calculates from movements
- `Ingredient::getTotalAllocatedStock()` - Excludes out-of-stock supplies
- `ProductionAllocationService::allocate()` - Validates supply is in stock
- `ProductionAllocationService::consume()` - Updates `last_used_at`

## Task Template Pivot Table Structure

The task template system uses a pivot table with an auto-incrementing `id` column for Filament repeater compatibility:

### Table: `task_template_task_type`
- `id` - Auto-incrementing primary key (required for Filament repeaters)
- `task_template_id` - Foreign key to task_templates
- `production_task_type_id` - Foreign key to production_task_types
- `sort_order` - Display order
- `offset_days` - Days after production start
- `skip_weekends` - Whether to skip weekends when scheduling
- `duration_override` - Optional duration override (null = use task type default)
- `created_at`, `updated_at` - Timestamps

### Why Auto-Incrementing ID?
Filament's Repeater component requires a single primary key to track existing vs new records. While a composite key (`task_template_id`, `production_task_type_id`) works for database integrity, it doesn't work well with Filament's form handling.

### Model Configuration
```php
class TaskTemplateTaskType extends Pivot
{
    public $incrementing = true;  // Required for Filament repeaters
    protected $table = 'task_template_task_type';
    // ...
}
```

### Key Locations
- Migration: `2026_03_03_061116_add_id_to_task_template_task_type_table.php`
- Model: `app/Models/Production/TaskTemplateTaskType.php`
- Form: `app/Filament/Resources/TaskTemplates/Schemas/TaskTemplateForm.php`

## Code Generation and Naming Conventions

### Formula Codes
- **Format**: `FRM-XXXX` (e.g., `FRM-0001`, `FRM-0042`)
- **Uniqueness**: Enforced at database level (`formulas_code_unique` index)
- **Auto-generation**: New formulas automatically get next available code
- **Duplication**: When duplicating a formula, a new unique code is auto-generated
- **Editable**: Formula codes can be modified after creation

**Key Locations:**
- `FormulaResource::generateUniqueFormulaCode()` - Generates unique codes
- `FormulaResource::duplicateFormula()` - Auto-generates code on duplication
- `FormulaResource::form()` - Code field configuration

### Ingredient Codes
- **Format**: `CATEGORYXXX` (e.g., `EO003`, `OIL042`, `ACT001`)
- **No dash**: Sequential number is appended directly to category code
- **Fallback**: `INGXXXX` when no category or category has no code
- **Auto-generation**: Generated automatically on ingredient creation based on category
- **Based on**: Ingredient category `code` field

**Examples:**
- Category "Huiles Essentielles" with code `EO` → Ingredients: `EO001`, `EO002`, `EO003`
- Category "Actifs" with code `ACT` → Ingredients: `ACT001`, `ACT002`
- No category → `ING0001`, `ING0002`

**Key Locations:**
- `Ingredient::generateUniqueCode()` - Model method for code generation
- `Ingredient::boot()` - Auto-generates code on create if empty
- `IngredientResource::generateIngredientCodePreview()` - Shows preview in form

### Ingredient Category Codes
- **Required**: Cannot create a category without a code
- **Unique**: Enforced at database level (`ingredient_categories_code_unique` index)
- **Usage**: Used as prefix for ingredient codes
- **Format**: Short uppercase code (e.g., `EO`, `OIL`, `ACT`, `HE`)

**Key Locations:**
- `IngredientCategoryResource::form()` - Required and unique validation
- Database unique index on `ingredient_categories.code`

### Supply Batch Numbers
- **Supplier batch numbers**: Can have duplicates when receiving same lot in different orders
- **Auto-suffix**: System automatically appends sequential suffix for duplicates
- **Format**: Original: `ABC123`, Duplicate: `ABC123-1`, Next: `ABC123-2`
- **Empty batches**: Falls back to `NO-BATCH-YYYYMMDDhhmmss`
- **Display**: Supplier batch is written on the bottle

**Key Locations:**
- `InventoryMovementService::generateUniqueBatchNumber()` - Handles duplicate detection and suffix generation
- `InventoryMovementService::receiveOrderItemIntoStock()` - Applies unique batch number on supply creation

## Task Templates - Product Type Relationship

Task templates can be shared across multiple product types via a pivot table.

### Architecture

```
TaskTemplate
└── BelongsToMany ProductTypes (via product_type_task_template pivot)
    └── withPivot('is_default')

ProductType
└── BelongsToMany TaskTemplates (via product_type_task_template pivot)
    └── withPivot('is_default')
    └── defaultTaskTemplate() - returns template where is_default=true
```

### Use Case Example

One task template shared across multiple soap sizes:
- TaskTemplate: "Standard Soap Workflow"
  - Mélange, Moulage, Séchage, Conditionnement
  - Linked to ProductType "Savon 100g" (is_default=true)
  - Linked to ProductType "Savon 150g" (is_default=true)
  - Linked to ProductType "Savon 200g" (is_default=true)

Separate QC templates per size (different target values):
- QC Template: "QC Soap 100g" → ProductType "Savon 100g"
- QC Template: "QC Soap 150g" → ProductType "Savon 150g"

### Pivot Table Structure
- `product_type_id` - Foreign key to product_types
- `task_template_id` - Foreign key to task_templates
- `is_default` - Boolean, which template to use automatically
- Timestamps

### Key Locations
- Migration: `2026_03_03_095123_create_product_type_task_template_pivot_table.php`
- Models: 
  - `TaskTemplate::productTypes()` - BelongsToMany relationship
  - `ProductType::taskTemplates()` - BelongsToMany relationship
  - `ProductType::defaultTaskTemplate()` - Get default template
- Service: `TaskGenerationService::getTaskTemplateForProduction()` - Queries via pivot table

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5.2
- filament/filament (FILAMENT) - v5
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v3
- phpunit/phpunit (PHPUNIT) - v11

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `pest-testing` — Tests applications using the Pest 3 PHP framework. Activates when writing tests, creating unit or feature tests, adding assertions, testing Livewire components, architecture testing, debugging test failures, working with datasets or mocking; or when the user mentions test, spec, TDD, expects, assertion, coverage, or needs to verify functionality works.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - `public function __construct(public GitHub $github) { }`
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<!-- Explicit Return Types and Method Params -->
```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
```

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.
- CRITICAL: ALWAYS use `search-docs` tool for version-specific Pest documentation and updated code examples.
- IMPORTANT: Activate `pest-testing` every time you're working with a Pest or testing-related task.

=== filament/filament rules ===

## Filament

- Filament is used by this application. Follow existing conventions for how and where it's implemented.
- Filament is a Server-Driven UI (SDUI) framework for Laravel that lets you define user interfaces in PHP using structured configuration objects. Built on Livewire, Alpine.js, and Tailwind CSS.
- Use the `search-docs` tool for official documentation on Artisan commands, code examples, testing, relationships, and idiomatic practices.

### Artisan

- Use Filament-specific Artisan commands to create files. Find them with `list-artisan-commands` or `php artisan --help`.
- Inspect required options and always pass `--no-interaction`.

### Patterns

Use static `make()` methods to initialize components. Most configuration methods accept a `Closure` for dynamic values.

Use `Get $get` to read other form field values for conditional logic:

<code-snippet name="Conditional form field" lang="php">
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

Select::make('type')
    ->options(CompanyType::class)
    ->required()
    ->live(),

TextInput::make('company_name')
    ->required()
    ->visible(fn (Get $get): bool => $get('type') === 'business'),

</code-snippet>

Use `state()` with a `Closure` to compute derived column values:

<code-snippet name="Computed table column" lang="php">
use Filament\Tables\Columns\TextColumn;

TextColumn::make('full_name')
    ->state(fn (User $record): string => "{$record->first_name} {$record->last_name}"),

</code-snippet>

Actions encapsulate a button with optional modal form and logic:

<code-snippet name="Action with modal form" lang="php">
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

Action::make('updateEmail')
    ->form([
        TextInput::make('email')->email()->required(),
    ])
    ->action(fn (array $data, User $record): void => $record->update($data)),

</code-snippet>

### Testing

Authenticate before testing panel functionality. Filament uses Livewire, so use `livewire()` or `Livewire::test()`:

<code-snippet name="Filament Table Test" lang="php">
    livewire(ListUsers::class)
        ->assertCanSeeTableRecords($users)
        ->searchTable($users->first()->name)
        ->assertCanSeeTableRecords($users->take(1))
        ->assertCanNotSeeTableRecords($users->skip(1));

</code-snippet>

<code-snippet name="Filament Create Resource Test" lang="php">
    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Test',
            'email' => 'test@example.com',
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseHas(User::class, [
        'name' => 'Test',
        'email' => 'test@example.com',
    ]);

</code-snippet>

<code-snippet name="Testing Validation" lang="php">
    livewire(CreateUser::class)
        ->fillForm([
            'name' => null,
            'email' => 'invalid-email',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'email' => 'email',
        ])
        ->assertNotNotified();

</code-snippet>

<code-snippet name="Calling Actions" lang="php">
    use Filament\Actions\DeleteAction;
    use Filament\Actions\Testing\TestAction;

    livewire(EditUser::class, ['record' => $user->id])
        ->callAction(DeleteAction::class)
        ->assertNotified()
        ->assertRedirect();

    livewire(ListUsers::class)
        ->callAction(TestAction::make('promote')->table($user), [
            'role' => 'admin',
        ])
        ->assertNotified();

</code-snippet>

### Common Mistakes

**Commonly Incorrect Namespaces:**
- Form fields (TextInput, Select, etc.): `Filament\Forms\Components\`
- Infolist entries (for read-only views) (TextEntry, IconEntry, etc.): `Filament\Infolists\Components\`
- Layout components (Grid, Section, Fieldset, Tabs, Wizard, etc.): `Filament\Schemas\Components\`
- Schema utilities (Get, Set, etc.): `Filament\Schemas\Components\Utilities\`
- Actions: `Filament\Actions\` (no `Filament\Tables\Actions\` etc.)
- Icons: `Filament\Support\Icons\Heroicon` enum (e.g., `Heroicon::PencilSquare`)

**Recent breaking changes to Filament:**
- File visibility is `private` by default. Use `->visibility('public')` for public access.
- `Grid`, `Section`, and `Fieldset` no longer span all columns by default.

</laravel-boost-guidelines>
