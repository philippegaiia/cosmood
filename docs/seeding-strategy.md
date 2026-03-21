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
- Apply new migrations and then re-seed curated production-safe data without dropping tables:
  - `composer run seed:sync:prod`
  - or run `php artisan migrate --no-interaction` followed by `composer run seed:prod`
- Seed development extras:
  - `composer run seed:dev`
  - or `php artisan db:seed --class=DevelopmentDatabaseSeeder --no-interaction`
- Apply new migrations and then re-seed the development entry point without dropping tables:
  - `composer run seed:sync:dev`
- Fresh DB + production-safe seed:
  - `composer run seed:fresh:prod`
- Fresh DB + development seed:
  - `composer run seed:fresh:dev`

Use the `seed:sync:*` commands when you need newly added migration columns but want
to keep existing records. Reserve the `seed:fresh:*` commands for disposable local
databases only, because they wipe all data before reseeding.

## Data Ownership

### Access/bootstrap seeders

- `database/seeders/ShieldRolesSeeder.php`
  - Generates Shield permissions for the `admin` panel when missing in non-production environments.
  - Syncs the base roles:
    - `super_admin`
    - `manager`
    - `planner`
    - `operator`

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

- `DevelopmentDatabaseSeeder` currently mirrors `ProductionDatabaseSeeder`.
- Add demo-only records there if we intentionally reintroduce disposable local fixtures later.
- Demo production flows should respect lifecycle contract (`planned -> confirmed -> ongoing -> finished`) and use production outputs for end-of-run reconciliation instead of `cancelled`.

## Current Guardrails

- Legacy overlapping seeders removed to avoid duplicate/conflicting sources.
- Formula seeders upsert formulas and restore their default product pivot link without deleting extra user-added links.
- Several demo seeders early-return when data already exists, reducing accidental re-seeding conflicts.
- `ProductionDatabaseSeeder` now bootstraps Shield roles before domain data and reassigns `admin@admin.com` to `super_admin`.
- `ShieldRolesSeeder` refuses to auto-generate permissions in production, so production deployments must keep Shield generation explicit.

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
