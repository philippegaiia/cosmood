# Developer Guide: Edit Locking For Filament Resources

This document explains the current edit-locking pattern used to protect long-lived Filament edit pages from both stale writes and concurrent editors.

## Goal

The app already uses transactional database locks inside services for short-lived integrity concerns such as allocations, stock reception, and sequence generation.

The app now uses two complementary layers:

- optimistic locking for stale-write protection,
- presence locking for human editor coordination.
- light polling plus action-level presence-lock guards on view pages that expose write-capable relation-manager actions.

Optimistic locking solves this problem:

- a user opens an edit page,
- another user or a service updates the same business document,
- the first user must not silently overwrite those changes with a stale form.

Presence locking solves this problem:

- user A opens an edit page,
- user B opens the same record,
- user B should be warned and blocked immediately instead of editing in parallel for several minutes.

## Current Scope

Current aggregates using this pattern:

- `Production`
- `ProductionWave`
- `SupplierOrder`

Current page-level coverage:

- second-editor blocking is verified for all three edit pages,
- manager force-unlock takeover is verified on `ProductionWave` and `SupplierOrder`,
- optimistic reload-from-database is verified on `SupplierOrder`,
- `Production` view relation-manager write actions now poll every `30s` and refuse mutations when another user owns the edit lock,
- `Production` edit/view pages and `SupplierOrder` edit pages now show an advisory banner when the linked `ProductionWave` is actively edited by another user,
- stale-save rejection and aggregate version bumping are covered in the locking feature suite.

Current implementation files:

- `app/Filament/Traits/UsesOptimisticLocking.php`
- `app/Filament/Traits/UsesPresenceLock.php`
- `app/Services/OptimisticLocking/AggregateVersionService.php`
- `app/Services/OptimisticLocking/ResourcePresenceLockService.php`
- `app/Services/OptimisticLocking/OptimisticLockingContext.php`
- `app/Models/ResourceLock.php`
- `app/Models/Production/Concerns/BumpsParentProductionVersion.php`
- `app/Models/Production/Concerns/BumpsParentProductionWaveVersion.php`
- `app/Models/Supply/Concerns/BumpsParentSupplierOrderVersion.php`
- `app/Filament/Resources/Production/ProductionResource/Pages/EditProduction.php`
- `app/Filament/Resources/Production/ProductionWaves/Pages/EditProductionWave.php`
- `app/Filament/Resources/Supply/SupplierOrderResource/Pages/EditSupplierOrder.php`
- `resources/views/filament/pages/edit-record-with-optimistic-locking.blade.php`
- `resources/views/filament/partials/optimistic-locking-warning.blade.php`
- `resources/views/filament/partials/presence-locking-warning.blade.php`

## Core Rules

### 1. Lock the aggregate root, not every table

Use `lock_version` on the business document edited by humans:

- `productions.lock_version`
- `production_waves.lock_version`
- `supplier_orders.lock_version`

Child writes should invalidate the same edit session by bumping the parent aggregate version.

For `Production`, this includes changes to:

- production items,
- allocations,
- tasks,
- outputs,
- QC checks,
- service-driven production updates that affect the document state.

For `ProductionWave`, this includes:

- wave edits,
- stock decision changes tied to the wave.

For `SupplierOrder`, this includes:

- supplier order edits,
- repeater line changes on `supplier_order_items`.

### 2. Keep services authoritative

Background or service updates must not be blocked by an open browser tab.

Instead:

- services update the record normally,
- they bump the aggregate `lock_version`,
- open edit pages detect the version mismatch and refuse stale saves.

### 3. The save path must be atomic

Do not rely only on a pre-save version comparison.

The final record update must:

- lock the target row with `lockForUpdate()`,
- compare the current `lock_version` to the page snapshot,
- save only when they still match.

This is handled by `UsesOptimisticLocking::handleRecordUpdateWithOptimisticLock()`.

### 4. Warn early, block on save

The UX has two layers:

- live polling warning while the page is open,
- hard conflict block at save time.

The warning is helpful but not authoritative. The save-time check is the real protection.

### 5. Presence locking is a UX guard, not the source of truth

Presence locking uses the `resource_locks` table with:

- one current row per edited aggregate root,
- a per-tab token,
- heartbeat refresh,
- TTL expiry,
- manager/super-admin force unlock.

It coordinates humans opening the same page, but it does not replace optimistic locking.

If presence locking and optimistic locking disagree, optimistic locking wins because it reflects the actual database state.

### 6. Do not over-lock view pages

Do not acquire a presence lock simply because a user opened a read/view page.

For resources like `Production`, the view page may still expose mutating relation-manager actions such as:

- marking procurement items as ordered,
- finishing or replanning tasks.

In that case, prefer this lighter pattern:

- keep the view page readable for everyone,
- poll the affected relation-manager tables every `30s`,
- block only the mutating actions when another user owns the parent edit lock.

This avoids turning read-only consultation into a hard lock while still protecting against conflicting writes from secondary tabs.

### 7. Use advisory banners for parent aggregates before adding cross-locks

Some aggregates expose a parent planning document that can ripple changes into the child document.

Current example:

- `ProductionWave` planning can reschedule linked `Production` records or adjust linked `ProductionItem` procurement state.

When that parent document already has its own presence lock, prefer a lighter child-page pattern first:

- keep the child page editable and readable,
- poll the parent lock state every `30s`,
- show a clear advisory banner when another user owns the parent lock,
- block only the most collision-prone child actions if real team usage shows a need.

This keeps the UI informative for small teams without turning every upstream planning activity into a hard cross-resource lock.

## Shared Helper Pattern

### Edit page wiring

For an `EditRecord` page:

1. use `UsesOptimisticLocking`
2. use `UsesPresenceLock`
3. initialize both lock layers in `afterFill()`
4. check and increment version in `mutateFormDataBeforeSave()`
5. route `handleRecordUpdate()` through `handleRecordUpdateWithOptimisticLock()`
6. refresh the loaded version in `afterSave()`
7. include the shared edit page wrapper so both banners render

### Child model version bumping

For child models belonging to `Production`, use:

- `App\Models\Production\Concerns\BumpsParentProductionVersion`

Override `getProductionForVersionBump()` when the production is not directly available via `production_id`.

### Service-side bumping

If a service updates a production with `saveQuietly()` or `updateQuietly()`, it must explicitly bump the version through:

- `AggregateVersionService::bumpProductionVersion()`

Quiet writes bypass Eloquent observers, so this companion bump is mandatory.

### Supplier-order repeater suppression

`SupplierOrder` edit pages save both the root record and repeater line items in the same request.

To avoid line-item saves from self-invalidating the parent optimistic lock during that request:

- `OptimisticLockingContext` is bound as a request-scoped service,
- `EditSupplierOrder` wraps the save flow with `runWithoutSupplierOrderBumps()`,
- `BumpsParentSupplierOrderVersion` skips parent version bumps only inside that scoped save window.

Without the scoped binding, the container may hand back a fresh context instance and the repeater save can still bump the parent order mid-save.

### Presence-lock ownership

Presence locks are acquired when the edit page is filled and refreshed by polling.

Current defaults:

- poll every `15s`
- expire after `90s`
- same user opening a second tab transfers the lock to the newest tab
- managers and super-admins may force unlock from the banner

## Reload Behavior

The optimistic-lock warning banner must reload the actual record and refill the form state.

Do not use a plain Livewire `$refresh` for this.

Use:

- `UsesOptimisticLocking::reloadRecordFromDatabase()`

That method:

- refreshes the record,
- refills the form from current database attributes,
- resets the in-memory lock snapshot.

## Translations

Optimistic-locking strings live in:

- `lang/fr/optimistic-locking.php`
- `lang/en/optimistic-locking.php`
- `lang/es/optimistic-locking.php`

Presence-locking strings live in:

- `lang/fr/presence-locking.php`
- `lang/en/presence-locking.php`
- `lang/es/presence-locking.php`

This project loads app translations from `lang/`, not `resources/lang/`.

## Rollout Checklist For New Resources

For each aggregate:

1. Add `lock_version` column with default `0`
2. Add model cast for `lock_version`
3. Add aggregate-specific version bump service methods if needed
4. Add child-model or observer-driven version bumps
5. Update the Filament edit page to use `UsesOptimisticLocking`
6. Add `UsesPresenceLock` if the resource needs human editor coordination
7. Use the shared edit page wrapper that includes both banners
8. Add focused tests for:
   - aggregate version bumps,
   - stale save rejection,
   - reload behavior after external update,
   - second save after a successful save,
   - presence lock acquisition/blocking on the edit page

## Current Recommendation

Keep lower-risk reference data out of scope until real collaboration pressure appears there.
