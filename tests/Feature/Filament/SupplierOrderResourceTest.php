<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\Production\ProductionWaves\Pages\EditProductionWave;
use App\Filament\Resources\Supply\SupplierOrderResource\Pages\CreateSupplierOrder;
use App\Filament\Resources\Supply\SupplierOrderResource\Pages\EditSupplierOrder;
use App\Filament\Resources\Supply\SupplierOrderResource\Pages\ListSupplierOrders;
use App\Models\Production\ProductionWave;
use App\Models\ResourceLock;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    actingAs(User::factory()->create());
});

function createSupplierOrderRoleUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate($role));

    return $user;
}

describe('SupplierOrder Presence Locking', function () {
    it('blocks a second editor while another manager owns the edit page', function () {
        $firstUser = createSupplierOrderRoleUser('manager');
        $secondUser = createSupplierOrderRoleUser('manager');

        $order = SupplierOrder::factory()->create();

        actingAs($firstUser);

        Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
            ->assertSet('hasForeignPresenceLock', false);

        actingAs($secondUser);

        Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
            ->assertSet('hasForeignPresenceLock', true)
            ->assertSee(__('presence-locking.blocked_title'))
            ->assertDontSee('Exporter PO PDF');
    });

    it('lets a manager force unlock and take over the supplier order edit page', function () {
        $firstUser = createSupplierOrderRoleUser('manager');
        $secondUser = createSupplierOrderRoleUser('manager');

        $order = SupplierOrder::factory()->create();

        actingAs($firstUser);

        Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
            ->assertSet('hasForeignPresenceLock', false);

        actingAs($secondUser);

        Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
            ->assertSet('hasForeignPresenceLock', true)
            ->call('forceReleasePresenceLock')
            ->assertSet('hasForeignPresenceLock', false)
            ->assertSee('Détails Commande');

        expect(ResourceLock::query()->sole()->user_id)->toBe($secondUser->id);
    });

    it('shows an advisory banner on the supplier order edit page when the linked wave is locked by another manager', function () {
        $planner = createSupplierOrderRoleUser('manager');
        $buyer = createSupplierOrderRoleUser('manager');

        $wave = ProductionWave::factory()->create();
        $order = SupplierOrder::factory()->create([
            'production_wave_id' => $wave->id,
        ]);

        actingAs($planner);

        Livewire::test(EditProductionWave::class, ['record' => $wave->id])
            ->assertSet('hasForeignPresenceLock', false);

        actingAs($buyer);

        Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
            ->assertSet('hasForeignPresenceLock', false)
            ->assertSet('hasForeignWavePresenceLockAdvisory', true)
            ->assertSee(__('presence-locking.parent_wave_advisory_title'))
            ->assertSee($planner->name);
    });
});

it('lists supplier orders in table', function () {
    $orders = SupplierOrder::factory()->count(3)->create();

    Livewire::withQueryParams(['tab' => 'all'])
        ->test(ListSupplierOrders::class)
        ->assertCanSeeTableRecords($orders);
});

it('searches supplier orders by reference', function () {
    $orderA = SupplierOrder::factory()->create(['order_ref' => 'PO-ALPHA-001']);
    $orderB = SupplierOrder::factory()->create(['order_ref' => 'PO-BETA-001']);

    Livewire::withQueryParams(['tab' => 'all'])
        ->test(ListSupplierOrders::class)
        ->searchTable('ALPHA')
        ->assertCanSeeTableRecords([$orderA])
        ->assertCanNotSeeTableRecords([$orderB]);
});

it('shows a stock warning badge for checked orders missing stock entries in the list', function () {
    $listing = SupplierListing::factory()->create();
    $order = SupplierOrder::factory()->create([
        'supplier_id' => $listing->supplier_id,
        'order_status' => OrderStatus::Checked,
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'is_in_supplies' => 'Attente',
        'moved_to_stock_at' => null,
    ]);

    Livewire::withQueryParams(['tab' => 'all'])
        ->test(ListSupplierOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertSee('Stock manquant');
});

it('shows checked and stock-missing tabs in the supplier order list', function () {
    Livewire::withQueryParams(['tab' => 'all'])
        ->test(ListSupplierOrders::class)
        ->assertSee('Contrôlées')
        ->assertSee('Stock manquant');
});

it('filters the stock-missing tab to checked orders with pending stock entries only', function () {
    $listing = SupplierListing::factory()->create();

    $missingStockOrder = SupplierOrder::factory()->create([
        'supplier_id' => $listing->supplier_id,
        'order_status' => OrderStatus::Checked,
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $missingStockOrder->id,
        'supplier_listing_id' => $listing->id,
        'moved_to_stock_at' => null,
        'is_in_supplies' => 'Attente',
    ]);

    $completeOrder = SupplierOrder::factory()->create([
        'supplier_id' => $listing->supplier_id,
        'order_status' => OrderStatus::Checked,
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $completeOrder->id,
        'supplier_listing_id' => $listing->id,
        'moved_to_stock_at' => now(),
        'is_in_supplies' => 'Stock',
    ]);

    $deliveredOrder = SupplierOrder::factory()->create([
        'supplier_id' => $listing->supplier_id,
        'order_status' => OrderStatus::Delivered,
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $deliveredOrder->id,
        'supplier_listing_id' => $listing->id,
        'moved_to_stock_at' => null,
        'is_in_supplies' => 'Attente',
    ]);

    Livewire::withQueryParams(['tab' => 'stock-missing'])
        ->test(ListSupplierOrders::class)
        ->assertCanSeeTableRecords([$missingStockOrder])
        ->assertCanNotSeeTableRecords([$completeOrder, $deliveredOrder]);
});

it('prefills delivery date from supplier estimated delivery days', function () {
    $supplier = Supplier::factory()->create([
        'estimated_delivery_days' => 5,
        'code' => 'CAU',
    ]);

    Livewire::test(CreateSupplierOrder::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'order_date' => '2026-03-07',
            'order_status' => OrderStatus::Draft,
        ])
        ->assertSet('data.delivery_date', fn (string $value): bool => str_starts_with($value, '2026-03-12'));
});

it('uses default lead time of 8 days when supplier lead time is not customized', function () {
    $supplier = Supplier::factory()->create([
        'code' => 'DEF',
        'estimated_delivery_days' => 8,
    ]);

    Livewire::test(CreateSupplierOrder::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'order_date' => '2026-03-07',
            'order_status' => OrderStatus::Draft,
        ])
        ->assertSet('data.delivery_date', fn (string $value): bool => str_starts_with($value, '2026-03-15'));
});

it('creates a supplier order with items in one pass', function () {
    actingAs(createSupplierOrderRoleUser('planner'));

    $supplier = Supplier::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'supplier_code' => 'IBC-920',
        'unit_weight' => 920,
        'unit_of_measure' => 'kg',
    ]);

    Livewire::test(CreateSupplierOrder::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'order_status' => OrderStatus::Draft,
            'order_date' => '2026-03-15',
            'delivery_date' => '2026-03-22',
            'supplier_order_items' => [[
                'supplier_listing_id' => $listing->id,
                'quantity' => 1,
                'unit_weight' => 920,
                'unit_price' => null,
                'batch_number' => null,
                'expiry_date' => null,
                'committed_quantity_kg' => 0,
                'is_in_supplies' => 'Attente',
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = SupplierOrder::query()->where('supplier_id', $supplier->id)->latest('id')->first();

    expect($order)->not->toBeNull()
        ->and($order->supplier_order_items)->toHaveCount(1)
        ->and($order->supplier_order_items->first()->supplier_listing_id)->toBe($listing->id)
        ->and((float) $order->supplier_order_items->first()->quantity)->toBe(1.0);
});

it('blocks decimal quantities for unit-based supplier listings on create', function () {
    actingAs(createSupplierOrderRoleUser('planner'));

    $supplier = Supplier::factory()->create();
    $ingredient = Ingredient::factory()->unitBased()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'supplier_code' => 'BOX-001',
        'unit_weight' => 0,
        'unit_of_measure' => 'u',
    ]);

    Livewire::test(CreateSupplierOrder::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'order_status' => OrderStatus::Draft,
            'order_date' => '2026-03-15',
            'delivery_date' => '2026-03-22',
            'supplier_order_items' => [[
                'supplier_listing_id' => $listing->id,
                'quantity' => 1.5,
                'unit_weight' => 0,
                'unit_price' => 0.42,
                'batch_number' => null,
                'expiry_date' => null,
                'committed_quantity_kg' => 0,
                'is_in_supplies' => 'Attente',
            ]],
        ])
        ->call('create')
        ->assertHasFormErrors([
            'supplier_order_items.0.quantity',
        ]);
});

it('saves edited order when adding a wave after item creation without engagement value', function () {
    actingAs(createSupplierOrderRoleUser('planner'));

    $supplier = Supplier::factory()->create();
    $wave = ProductionWave::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'production_wave_id' => null,
        'order_status' => OrderStatus::Draft,
    ]);

    $item = SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'committed_quantity_kg' => 0,
    ]);

    Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
        ->fillForm([
            'production_wave_id' => $wave->id,
            'order_status' => OrderStatus::Passed,
            'supplier_order_items' => [[
                'id' => $item->id,
                'supplier_listing_id' => $item->supplier_listing_id,
                'quantity' => (float) $item->quantity,
                'unit_weight' => (float) $item->unit_weight,
                'unit_price' => (float) $item->unit_price,
                'batch_number' => $item->batch_number,
                'expiry_date' => optional($item->expiry_date)->format('Y-m-d'),
                'committed_quantity_kg' => null,
                'is_in_supplies' => $item->is_in_supplies,
            ]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $order->fresh()->supplier_order_items()->sole()->committed_quantity_kg)->toBe(0.0)
        ->and($order->fresh()->production_wave_id)->toBe($wave->id);
});

it('blocks planners from moving a supplier order to delivered', function () {
    actingAs(createSupplierOrderRoleUser('planner'));

    $order = SupplierOrder::factory()->confirmed()->create();

    Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
        ->fillForm([
            'order_status' => OrderStatus::Delivered,
        ])
        ->call('save')
        ->assertNotified();

    expect($order->fresh()->order_status)->toBe(OrderStatus::Confirmed);
});

it('allows managers to move a supplier order to checked', function () {
    actingAs(createSupplierOrderRoleUser('manager'));

    $order = SupplierOrder::factory()->delivered()->create();

    Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
        ->fillForm([
            'order_status' => OrderStatus::Checked,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->fresh()->order_status)->toBe(OrderStatus::Checked);
});

it('blocks moving an order to checked when pending lines are not receipt-ready', function () {
    actingAs(createSupplierOrderRoleUser('manager'));

    $listing = SupplierListing::factory()->create();
    $order = SupplierOrder::factory()->create([
        'supplier_id' => $listing->supplier_id,
        'order_status' => OrderStatus::Delivered,
        'delivery_date' => now()->toDateString(),
    ]);

    $item = SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'unit_price' => 3.2,
        'batch_number' => null,
        'expiry_date' => null,
        'is_in_supplies' => 'Attente',
        'moved_to_stock_at' => null,
    ]);

    Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
        ->fillForm([
            'order_status' => OrderStatus::Checked,
            'delivery_date' => $order->delivery_date?->toDateString(),
            'supplier_order_items' => [[
                'id' => $item->id,
                'supplier_listing_id' => $item->supplier_listing_id,
                'quantity' => (float) $item->quantity,
                'unit_weight' => (float) $item->unit_weight,
                'unit_price' => (float) $item->unit_price,
                'batch_number' => null,
                'expiry_date' => null,
                'committed_quantity_kg' => (float) $item->committed_quantity_kg,
                'is_in_supplies' => $item->is_in_supplies,
                'moved_to_stock_at' => null,
            ]],
        ])
        ->call('save')
        ->assertNotified();

    expect($order->fresh()->order_status)->toBe(OrderStatus::Delivered);
});

it('shows supplier order status and stock progress immediately on edit', function () {
    actingAs(createSupplierOrderRoleUser('manager'));

    $listing = SupplierListing::factory()->create([
        'supplier_code' => 'ARG-025',
    ]);
    $order = SupplierOrder::factory()->create([
        'supplier_id' => $listing->supplier_id,
        'order_status' => OrderStatus::Checked,
        'delivery_date' => now()->toDateString(),
        'order_ref' => 'PO-SUMMARY-001',
    ]);

    $stockedItem = SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'is_in_supplies' => 'Stock',
        'moved_to_stock_at' => now(),
    ]);

    Supply::factory()->create([
        'supplier_listing_id' => $listing->id,
        'supplier_order_item_id' => $stockedItem->id,
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'unit_price' => 4.5,
        'batch_number' => 'LOT-READY',
        'expiry_date' => now()->addYear(),
        'is_in_supplies' => 'Attente',
        'moved_to_stock_at' => null,
    ]);

    $page = Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
        ->assertSee('Nb UOM')
        ->assertSee('UOM')
        ->assertSee('Total (kg)')
        ->assertSee('1 / 2 lignes en stock')
        ->assertSee('1 / 1 lignes prêtes')
        ->assertSee('1 ligne contrôlée sans entrée de stock')
        ->assertSee('Code fournisseur: ARG-025');

    expect($page->instance()->getTitle())
        ->toContain('PO-SUMMARY-001')
        ->toContain('Contrôlée');
});

it('shows unit-based supplier listing labels with parenthesized uom and dynamic field labels', function () {
    actingAs(createSupplierOrderRoleUser('manager'));

    $supplier = Supplier::factory()->create();
    $ingredient = Ingredient::factory()->unitBased()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'name' => 'Boite tres doux',
        'supplier_code' => 'BTD-001',
        'unit_weight' => 1,
        'unit_of_measure' => 'u',
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'order_status' => OrderStatus::Draft,
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'quantity' => 12,
        'unit_weight' => 1,
        'committed_quantity_kg' => 12,
    ]);

    Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
        ->assertSee('Boite tres doux (1 u)')
        ->assertSee('UOM (unités)')
        ->assertSee('Total (u)');
});

it('reloads the latest supplier order form state including repeater items after an external update', function () {
    actingAs(createSupplierOrderRoleUser('manager'));

    $listing = SupplierListing::factory()->create();
    $order = SupplierOrder::factory()->create([
        'supplier_id' => $listing->supplier_id,
        'description' => 'Initial description',
    ]);

    $item = SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'batch_number' => 'LOT-INITIAL',
    ]);

    $page = Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
        ->assertSet('data.description', 'Initial description')
        ->assertSet('data.supplier_order_items', fn (?array $items): bool => collect($items ?? [])
            ->contains(fn (array $itemState): bool => ($itemState['batch_number'] ?? null) === 'LOT-INITIAL'));

    $order->update([
        'description' => 'Updated by service',
    ]);

    $item->update([
        'batch_number' => 'LOT-UPDATED',
    ]);

    $page->call('reloadRecordFromDatabase')
        ->assertSet('data.description', 'Updated by service')
        ->assertSet('data.supplier_order_items', fn (?array $items): bool => collect($items ?? [])
            ->contains(fn (array $itemState): bool => ($itemState['batch_number'] ?? null) === 'LOT-UPDATED'));
});

it('blocks editing stocked lines from the supplier order edit page', function () {
    actingAs(createSupplierOrderRoleUser('manager'));

    $listing = SupplierListing::factory()->create();
    $order = SupplierOrder::factory()->create([
        'supplier_id' => $listing->supplier_id,
        'order_status' => OrderStatus::Checked,
        'delivery_date' => now()->toDateString(),
    ]);

    $item = SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'quantity' => 5,
        'unit_price' => 3.2,
        'batch_number' => 'LOT-LOCKED',
        'expiry_date' => now()->addYear(),
        'is_in_supplies' => 'Stock',
        'moved_to_stock_at' => now(),
    ]);

    Supply::factory()->create([
        'supplier_listing_id' => $listing->id,
        'supplier_order_item_id' => $item->id,
    ]);

    Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
        ->fillForm([
            'order_status' => OrderStatus::Checked,
            'supplier_order_items' => [[
                'id' => $item->id,
                'supplier_listing_id' => $item->supplier_listing_id,
                'quantity' => 9,
                'unit_weight' => (float) $item->unit_weight,
                'unit_price' => (float) $item->unit_price,
                'batch_number' => $item->batch_number,
                'expiry_date' => optional($item->expiry_date)->format('Y-m-d'),
                'committed_quantity_kg' => (float) $item->committed_quantity_kg,
                'is_in_supplies' => $item->is_in_supplies,
                'moved_to_stock_at' => optional($item->moved_to_stock_at)->toISOString(),
            ]],
        ])
        ->call('save')
        ->assertNotified();

    expect((float) $item->fresh()->quantity)->toBe(5.0);
});

it('blocks negative order item quantities with form validation', function () {
    $supplier = Supplier::factory()->create();
    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'order_status' => OrderStatus::Draft,
    ]);

    $item = SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
    ]);

    Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
        ->fillForm([
            'supplier_order_items' => [[
                'id' => $item->id,
                'supplier_listing_id' => $item->supplier_listing_id,
                'quantity' => -3,
                'unit_weight' => (float) $item->unit_weight,
                'unit_price' => (float) $item->unit_price,
                'batch_number' => $item->batch_number,
                'expiry_date' => optional($item->expiry_date)->format('Y-m-d'),
                'committed_quantity_kg' => 0,
                'is_in_supplies' => $item->is_in_supplies,
            ]],
        ])
        ->call('save')
        ->assertHasFormErrors([
            'supplier_order_items.0.quantity' => 'min',
        ]);
});

it('shows a notification and keeps the order when deleting a non-empty supplier order', function () {
    $order = SupplierOrder::factory()->create();

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
    ]);

    Livewire::test(EditSupplierOrder::class, ['record' => $order->id])
        ->callAction(DeleteAction::class)
        ->assertNotified();

    expect(SupplierOrder::query()->find($order->id))->not->toBeNull();
});
