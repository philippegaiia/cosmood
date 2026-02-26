# Cosmood MRP - Implementation Plan

## Overview

Small-manufacturing MRP for soap making and cosmetics. Simple, opinionated, and enjoyable.

**Workflow:** plan (tasks + reservations) → procure/order → start production (consume process inputs) → finish (consume packaging) → track

## Core Concepts

### Batch Sizing
- **Soaps**: oil-weight-driven (input: oils_kg → output: units)
- **Balms/Deos**: final-mass-driven (input: batch_kg → output: units)
- Product Types define default batch sizes and expected outputs

### Production Waves
- Container for planned batches
- Aggregates procurement requirements
- Status: draft → approved → in_progress → completed/cancelled
- Productions can be orphan (no wave) or belong to a wave

### Masterbatches
- Pre-produced phase (usually saponified oils) for productivity
- Replaces 100% of target phase
- Hand-picked by soapmaker (no auto-selection)
- Oils list kept in production but marked as fulfilled by MB
- PDF expands to show underlying oils + lot numbers

### Allocation
- Per-production visibility (not wave-level)
- Affects available quantity at reservation time
- Track: not_ordered → ordered → confirmed → received → allocated

### Production Lifecycle (Batch)
- Statuses: planned → confirmed → ongoing → finished
- Allowed cancellation: planned/confirmed/ongoing → cancelled
- `finished` and `cancelled` are terminal (no backward transitions)
- `production_date` is planning anchor; real start is explicit `ongoing` action
- Tasks are generated from planning stage for hour estimation and scheduling
- Reserved stock is unavailable immediately (`allocated_quantity` impact)
- Stage consumption:
  - `ongoing`: oils/lye/additives/masterbatch consumption
  - `finished`: packaging consumption

---

## Database Schema

### New Tables

#### product_types
```
id, name, slug
product_category_id (FK)
sizing_mode: oil_weight/final_mass/units
default_batch_size (decimal)
expected_units_output (integer)
expected_waste_kg (decimal, nullable)
unit_fill_size (decimal, nullable)
is_active
timestamps, soft_deletes
```

#### batch_size_presets
```
id, product_type_id
name
batch_size, expected_units, expected_waste_kg
is_default
timestamps
```

#### production_waves
```
id, name, slug
status: draft/approved/in_progress/completed/cancelled
planned_start_date, planned_end_date
approved_at, approved_by (FK users)
started_at, completed_at
notes
timestamps, soft_deletes
```

#### production_ingredient_requirements
```
id, production_id, wave_id
ingredient_id, phase
supplier_listing_id (nullable)
required_quantity (kg)
status: not_ordered/ordered/confirmed/received/allocated
allocated_quantity, allocated_from_supply_id (nullable)
fulfilled_by_masterbatch_id (FK productions, nullable)
is_collapsed_in_ui (boolean)
timestamps
```

#### production_packaging_requirements
```
id, production_id, wave_id
packaging_name, packaging_code
required_quantity (units)
supplier_id (nullable)
unit_cost (nullable)
status: not_ordered/ordered/confirmed/received/allocated
allocated_quantity
timestamps
```

#### task_templates
```
id, name, product_category_id
is_default
timestamps, soft_deletes
```

#### task_template_items
```
id, task_template_id
name, duration_hours
offset_days
skip_weekends (boolean)
sort_order
timestamps
```

### Modified Tables

#### productions ADD
```
wave_id (nullable)
product_type_id (nullable)
batch_size_preset_id (nullable)
sizing_mode
planned_quantity, expected_units, expected_waste_kg
actual_units (nullable)
replaces_phase (nullable) -- for MB productions
masterbatch_lot_id (nullable) -- selected MB for this batch
```

#### formulas ADD
```
replaces_phase (nullable) -- for MB formulas
```

#### supplies ADD
```
allocated_quantity (default 0)
```

#### supplier_order_items ADD
```
allocated_to_production_id (nullable)
allocated_quantity (default 0)
```

#### production_tasks ADD
```
task_template_item_id (nullable)
source: template/manual
scheduled_date
cancelled_at, cancelled_reason
```

#### products ADD
```
product_type_id (nullable)
```

---

## Models

### New Models
- `ProductType` with `batchSizePresets()`, `productCategory()`, `productions()`
- `BatchSizePreset` with `productType()`
- `ProductionWave` with `productions()`, `ingredientRequirements()`, `packagingRequirements()`
- `ProductionIngredientRequirement` with `production()`, `wave()`, `ingredient()`, `fulfilledByMasterbatch()`
- `ProductionPackagingRequirement` with `production()`, `wave()`
- `TaskTemplate` with `productCategory()`, `items()`
- `TaskTemplateItem` with `taskTemplate()`, `productionTasks()`

### Updated Models
- `Production` - add wave, productType, masterbatchLot, requirements relationships
- `Formula` - add replaces_phase
- `Supply` - add allocation tracking
- `SupplierOrderItem` - add allocation
- `ProductionTask` - add template source, cancellation
- `Product` - add productType

---

## Services

```
app/Services/
├── FormulaQuantityService.php      - Calculate quantities from formula
├── ProductionRequirementsService.php - Generate requirements, handle MB
├── WaveProcurementService.php      - Aggregate requirements, create POs
├── AllocationService.php           - Allocate supplies, track availability
├── TaskGenerationService.php       - Generate tasks from templates
├── SupplyReceivingService.php      - Receive PO items, create supplies
└── MasterbatchService.php          - MB selection, validation, expansion
```

---

## Filament Resources

### New Resources
- `ProductionWaveResource` - Wave planning and procurement
- `ProductTypeResource` - Product types with batch presets
- `TaskTemplateResource` - Task templates management

### Enhanced Resources
- `ProductionResource` - Wave selector, product type, requirements, MB selector, readiness
- `SupplyResource` - Show allocated vs available
- `SupplierOrderResource` - Receive items, allocate to productions

---

## Test Structure

```
tests/Feature/
├── Production/
│   ├── ProductionTest.php
│   ├── ProductionRequirementsTest.php
│   ├── MasterbatchTest.php
│   └── TaskGenerationTest.php
├── Wave/
│   ├── ProductionWaveTest.php
│   ├── WaveApprovalTest.php
│   └── WaveProcurementTest.php
├── Supply/
│   ├── AllocationTest.php
│   └── SupplyReceivingTest.php
└── Models/
    ├── ProductTypeTest.php
    ├── TaskTemplateTest.php
    └── FormulaQuantityServiceTest.php
```

---

## Implementation Phases

| Phase | Tasks | Status |
|-------|-------|--------|
| 1. Migrations | Create new tables, modify existing | ✅ Done |
| 2. Models | New models + relationships | ✅ Done |
| 3. ProductType | Resource + tests | ✅ Done |
| 4. Production Basics | Batch sizing, tests | ✅ Done |
| 5. Formula Calculator | Service + tests | ✅ Done |
| 6. Production Requirements | Service + tests | ✅ Done |
| 7. Masterbatch | Service + tests | ✅ Done |
| 8. Wave Creation | Resource + tests | ✅ Done |
| 9. Wave Approval | Workflow + tests | ✅ Done |
| 10. Procurement | Service + tests | ✅ Done |
| 11. Allocation | Service + tests | ✅ Done |
| 12. Task Templates | Resource + tests | ✅ Done |
| 13. Task Generation | Service + tests | ✅ Done |
| 14. Packaging | Requirements + tests | ✅ Done |
| 15. Polish | UI/UX, validation | 🔲 Remaining |

---

## Key Decisions

1. **Orphan Productions**: Productions can exist without wave (standalone lifecycle)
2. **Masterbatch Option B**: Keep oil lines, mark fulfilled by MB, collapse in UI, expand in PDF
3. **Allocation**: Per-production visibility, not wave-level
4. **Batch Sizing**: Product Types with multiple presets
5. **Packaging**: Tracked as separate requirements (units, not kg)
6. **Weekend Handling**: Skip weekends for task scheduling
7. **Auth**: Single panel, Filament Shield later

---

## Definition of Done

- [ ] Plan a wave → generate procurement → create POs → see per-batch readiness
- [ ] Reserving/allocating affects "available" immediately
- [ ] Planning/confirming a batch generates tasks (no weekends)
- [ ] Cancelling a batch deletes tasks (soft delete) and releases reservations
- [ ] Formulas scale correctly (% and quantities)
- [ ] Masterbatch selection collapses oils in UI, expands in PDF
- [ ] Orphan productions work independently
- [ ] All core workflows have feature tests
