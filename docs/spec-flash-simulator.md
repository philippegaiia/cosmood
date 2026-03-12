# Flash Simulator Spec

## Objective

The simulator provides fast planning estimates for ingredients and oils load based on fixed production batch formats.

By default it is non-persistent and used for planning decisions.

An optional conversion flow can persist one simulation into a production wave with generated production batches.

It does not reserve or consume inventory; reservation/consumption happens only through the production lifecycle.

## Inputs per Line

- Product search + product selection.
- Desired units (`quantite demandee`).
- Batch format (`format de batch`) from product type batch presets.

## Batch Logic

For each line:

- `units_per_batch` from selected preset (`expected_units`).
- `batch_size_kg` from selected preset (`batch_size`).
- `batches_required = ceil(desired_units / units_per_batch)`.
- `produced_units = batches_required * units_per_batch`.
- `extra_units = produced_units - desired_units`.
- `oils_kg = batches_required * batch_size_kg`.

Example:

- Desired `3200`, preset `288 units / 26 kg`.
- `3200 / 288 = 11.11` -> `12` batches.
- `produced_units = 3456`, `extra_units = 256`.
- `oils_kg = 312`.

## Formula, Ingredients, and Packaging

- Active formula is resolved for selected product.
- Ingredient totals aggregate formula item requirements against computed oils kg.
- Unit-based lines (`qty_per_unit`) are excluded from ingredient kg requirement totals.
- Compatibility fallback: packaging phase and unit-base ingredients are treated as unit-based even when old rows still miss explicit mode.
- Product packaging (`product_packaging.quantity_per_unit`) is included in totals using `produced_units` as the multiplier.
- Packaging rows are merged in the same totals table as ingredients and keep their own base unit (`u` or `kg`) for planning and cost estimation.

## Outputs

- Summary totals:
  - products count,
  - desired units,
  - produced units,
  - extra units,
  - total batches,
  - total oils kg,
  - estimated cost.
  - total duration displayed as rounded hours in the UI.
- Ingredient totals table.
- Consolidated tasks table (global totals per task name, not per product line), with:
  - weighted average duration per batch,
  - cumulative batches,
  - total duration.
  - durations displayed in human-readable `h/min` format in the UI.
  - task names and durations are resolved from the same default task template source used by real production generation:
    - `taskTemplateTaskTypes -> taskType` first,
    - legacy `TaskTemplate.items` only as fallback for older templates.
- Per-product extra summary table.
- Per-line detail table.
- Print action (`Print`) to export current simulator view through browser print.
- Results are advisory for wave/batch sizing and procurement estimation, not stock commitment.

## Optional Persistence: Create Wave from Simulation

The simulator can now generate persistent planning records:

- Creates one `ProductionWave` (status `draft`) from current simulation lines.
- Generates one `Production` per computed batch (`batches_required`).
- Keeps standard production side effects through observers:
  - production items generation,
  - QC checks generation,
  - task generation.
- The simulator remains planning-first:
  - it creates the intended production batches only,
  - finish-side reconciliation (`production_outputs`, rebatch, scrap) happens later on the real production records and is intentionally outside simulator scope.

Planner options exposed from simulator:

- wave name,
- planning start date,
- skip weekends,
- skip holidays,
- fallback daily capacity when no production line is assigned.

Scheduling behavior:

- Date allocation is computed per production line capacity when product types define a default line.
- Existing `planned` and `confirmed` productions already present in the database are counted first, so simulator-created batches move to the next available slot instead of failing at persistence time.
- Different lines are planned independently in parallel (e.g. soap line and deodorant lab same day).
- Products without line assignment use fallback daily capacity.

## UX / Technical Constraints

- Dynamic row controls rely on stable `wire:key` to avoid Livewire/Flux DOM reuse issues.
- Batch preset select is rendered only when product is selected (prevents empty Flux select initialization issues).
- Row layout is kept in a fixed 12-column structure with horizontal overflow fallback for narrow viewports.

## File Map

- Livewire component: `app/Livewire/SimulateurFlashPage.php`
- Service logic: `app/Services/Production/FlashSimulationService.php`
- Wave conversion service: `app/Services/Production/FlashSimulationWavePlanner.php`
- Planning scheduler service: `app/Services/Production/WaveProductionPlanningService.php`
- UI template: `resources/views/livewire/simulateur-flash-page.blade.php`
- Filament page wrapper: `app/Filament/Pages/SimulateurFlash.php`

## Commenting Hotspots

Keep PHPDoc clear when touching:

- `FlashSimulationService::simulate()` for batch math invariants.
- `FlashSimulationService::resolveBatchConfiguration()` for preset/default fallback rules.
- `SimulateurFlashPage::updatedLines()` for dynamic field reset and re-render behavior.
