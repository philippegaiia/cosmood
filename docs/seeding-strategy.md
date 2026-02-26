# Seeding Strategy

## Goal

Keep production-safe seeding deterministic and based on curated real data.

Avoid factory-generated fake formulas/products in production-like environments.

## Seeder Entry Points

- Production-safe: `Database\\Seeders\\ProductionDatabaseSeeder`
- Development/demo: `Database\\Seeders\\DevelopmentDatabaseSeeder`
- Default `DatabaseSeeder` delegates to production-safe seeder.

## Commands

- Seed production-safe data:
  - `composer run seed:prod`
  - or `php artisan db:seed --class=ProductionDatabaseSeeder --no-interaction`
- Seed development extras:
  - `composer run seed:dev`
  - or `php artisan db:seed --class=DevelopmentDatabaseSeeder --no-interaction`
- Fresh DB + production-safe seed:
  - `composer run seed:fresh:prod`
- Fresh DB + development seed:
  - `composer run seed:fresh:dev`

## Data Ownership

### Real-data seeders (source of truth)

- `database/seeders/ProductSeeder.php`
- `database/seeders/Supply/IngredientCategorySeeder.php`
- `database/seeders/Supply/IngredientSeeder.php`
- `database/seeders/Supply/SupplierSeeder.php`
- `database/seeders/FormulasTableSeeder.php`
- `database/seeders/FormulaItemsTableSeeder.php`

### Support/config seeders

- `database/seeders/ProductTypeSeeder.php`
- `database/seeders/BatchSizePresetSeeder.php`
- `database/seeders/QcTemplateSeeder.php`
- `database/seeders/TaskTemplateSeeder.php`

### Development-only/demo extensions

- Waves, production tasks/items/requirements, supplier orders, supplies movement, contacts.
- Included in `DevelopmentDatabaseSeeder` only.
- Demo production flows should respect lifecycle contract (`planned -> confirmed -> ongoing -> finished|cancelled`) to avoid inconsistent stock side effects.

## Current Guardrails

- Legacy overlapping seeders removed to avoid duplicate/conflicting sources.
- Formula seeders use `updateOrInsert` (idempotent, non-destructive).
- Several demo seeders early-return when data already exists, reducing accidental re-seeding conflicts.

## Rules

- Do not seed formulas via factories for production-like data.
- Prefer business keys and deterministic records over random generation.
- Keep seeded stock examples compatible with reservation semantics (`allocated_quantity` reduces available stock).
- When changing production-safe seed structure, update this document and matching seeders in same branch.

## Commenting Hotspots

Keep concise PHPDoc/comments in:

- `database/seeders/ProductionDatabaseSeeder.php` (what is production-safe vs not)
- `database/seeders/DevelopmentDatabaseSeeder.php` (why dev extras exist)
- Formula table seeders (idempotency and non-destructive behavior)
