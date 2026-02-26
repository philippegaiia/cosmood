# Flash Simulator Spec

## Objective

The simulator provides fast planning estimates for ingredients and oils load based on fixed production batch formats.

It is non-persistent and used for planning decisions, not for creating production records.

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

## Formula and Ingredients

- Active formula is resolved for selected product.
- Ingredient totals aggregate formula item requirements against computed oils kg.
- Packaging phase lines are excluded from ingredient requirement totals.

## Outputs

- Summary totals:
  - products count,
  - desired units,
  - produced units,
  - extra units,
  - total batches,
  - total oils kg,
  - estimated cost.
- Ingredient totals table.
- Per-product extra summary table.
- Per-line detail table.

## UX / Technical Constraints

- Dynamic row controls rely on stable `wire:key` to avoid Livewire/Flux DOM reuse issues.
- Batch preset select is rendered only when product is selected (prevents empty Flux select initialization issues).
- Row layout is kept in a fixed 12-column structure with horizontal overflow fallback for narrow viewports.

## File Map

- Livewire component: `app/Livewire/SimulateurFlashPage.php`
- Service logic: `app/Services/Production/FlashSimulationService.php`
- UI template: `resources/views/livewire/simulateur-flash-page.blade.php`
- Filament page wrapper: `app/Filament/Pages/SimulateurFlash.php`

## Commenting Hotspots

Keep PHPDoc clear when touching:

- `FlashSimulationService::simulate()` for batch math invariants.
- `FlashSimulationService::resolveBatchConfiguration()` for preset/default fallback rules.
- `SimulateurFlashPage::updatedLines()` for dynamic field reset and re-render behavior.
