<?php

use App\Models\Production\ProductionWave;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('downloads supplier order pdf', function () {
    $this->actingAs(User::factory()->create());

    $supplier = Supplier::factory()->create([
        'name' => 'Test Supplier',
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'order_ref' => '2026-TS-1001',
        'freight_cost' => 35,
    ]);

    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'name' => 'Huile olive',
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'quantity' => 10,
        'unit_weight' => 2,
        'unit_price' => 4.5,
    ]);

    $response = $this->get(route('supplier-orders.po-pdf', $order));

    $response
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'attachment; filename=po-2026-TS-1001.pdf');
});

it('renders supplier order print view and keeps wave reference discreet', function () {
    $this->actingAs(User::factory()->create());

    $wave = ProductionWave::factory()->create([
        'name' => 'Mars 2026',
    ]);

    $order = SupplierOrder::factory()->create([
        'production_wave_id' => $wave->id,
        'order_ref' => '2026-TS-1002',
    ]);

    $response = $this->get(route('supplier-orders.po-print', $order));

    $response
        ->assertOk()
        ->assertSee('Ref commande: 2026-TS-1002')
        ->assertSee('Ref interne vague: Mars 2026')
        ->assertDontSee('Confirmation:');
});

it('renders copy email page for supplier order', function () {
    $this->actingAs(User::factory()->create());

    $supplier = Supplier::factory()->create([
        'name' => 'Copy Supplier',
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'order_ref' => '2026-TS-1003',
    ]);

    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'name' => 'Beurre karite',
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'quantity' => 4,
        'unit_weight' => 5,
        'unit_price' => 6,
    ]);

    $response = $this->get(route('supplier-orders.po-email-copy', $order));

    $response
        ->assertOk()
        ->assertSee('Sujet propose')
        ->assertSee('PO 2026-TS-1003 - Copy Supplier')
        ->assertSee('Copier-coller email fournisseur')
        ->assertSee('Veuillez trouver ci-dessous notre bon de commande 2026-TS-1003.')
        ->assertSee('Beurre karite');
});
