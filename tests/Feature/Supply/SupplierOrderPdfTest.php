<?php

use App\Enums\OrderStatus;
use App\Models\Production\ProductionWave;
use App\Models\Settings;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\User;
use App\Services\Supply\SupplierOrderDocumentBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

it('downloads supplier order pdf', function () {
    actingAs(User::factory()->create());

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

    $response = get(route('supplier-orders.po-pdf', $order));

    $response
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'attachment; filename=po-2026-TS-1001.pdf');
});

it('renders supplier order print view and keeps wave reference discreet', function () {
    actingAs(User::factory()->create());
    Settings::set('company_name', 'Laboratoires Horizon');
    Settings::set('company_address', "12 rue des Fleurs\n75001 Paris\nFrance");
    Settings::set('company_vat_number', 'FR12345678901');

    $wave = ProductionWave::factory()->create([
        'name' => 'Mars 2026',
    ]);

    $supplier = Supplier::factory()->create();

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'production_wave_id' => $wave->id,
        'order_ref' => '2026-TS-1002',
    ]);

    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'name' => 'Argile blanche',
        'supplier_code' => 'AB-778',
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'quantity' => 3,
        'unit_weight' => 25,
        'unit_price' => 2.5,
    ]);

    $response = get(route('supplier-orders.po-print', $order));

    $response
        ->assertOk()
        ->assertSee('Laboratoires Horizon')
        ->assertSee('12 rue des Fleurs')
        ->assertSee('TVA: FR12345678901')
        ->assertSee('Ref commande: 2026-TS-1002')
        ->assertSee('Ref interne vague: Mars 2026')
        ->assertSee('Code fournisseur')
        ->assertSee('UOM')
        ->assertSee('AB-778')
        ->assertDontSee('DLUO')
        ->assertDontSee('Statut:')
        ->assertDontSee('Facture:')
        ->assertDontSee('BL:')
        ->assertDontSee('EUR/kg')
        ->assertDontSee('EUR/u')
        ->assertDontSee('Confirmation:');
});

it('does not render status in the supplier order pdf template', function () {
    Settings::set('company_name', 'Laboratoires Horizon');
    Settings::set('company_address', "12 rue des Fleurs\n75001 Paris");
    Settings::set('company_vat_number', 'FR12345678901');

    $supplier = Supplier::factory()->create();
    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'order_status' => OrderStatus::Checked,
    ]);

    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'supplier_code' => 'PDF-001',
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
    ]);

    $html = view(
        'supply.supplier-orders.purchase-order-pdf',
        app(SupplierOrderDocumentBuilder::class)->buildViewData($order),
    )->render();

    expect($html)
        ->toContain('Laboratoires Horizon')
        ->toContain('TVA: FR12345678901')
        ->toContain('UOM')
        ->not->toContain('Statut:')
        ->not->toContain('DLUO')
        ->not->toContain('Facture:')
        ->not->toContain('BL:')
        ->not->toContain('EUR/kg')
        ->not->toContain('EUR/u');
});

it('renders copy email page for supplier order', function () {
    actingAs(User::factory()->create());
    Settings::set('company_name', 'Laboratoires Horizon');

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
        'supplier_code' => 'BK-001',
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'quantity' => 4,
        'unit_weight' => 5,
        'unit_price' => 6,
    ]);

    $response = get(route('supplier-orders.po-email-copy', $order));

    $response
        ->assertOk()
        ->assertSee('Sujet propose')
        ->assertSee('PO 2026-TS-1003 - Copy Supplier [BK-001]')
        ->assertSee('Copier-coller email fournisseur')
        ->assertSee('Code fournisseur')
        ->assertSee('Codes fournisseur BK-001')
        ->assertSee('Veuillez trouver ci-dessous notre bon de commande 2026-TS-1003.')
        ->assertSee('Codes fournisseur: BK-001')
        ->assertSee('Ref fournisseur: BK-001')
        ->assertSee('Laboratoires Horizon')
        ->assertSee('Beurre karite');
});

it('marks pricing as pending in supplier email copy when rates are not yet filled', function () {
    actingAs(User::factory()->create());

    $supplier = Supplier::factory()->create([
        'name' => 'Pending Rates Supplier',
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'order_ref' => '2026-TS-1004',
    ]);

    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'name' => 'Huile tournesol',
        'supplier_code' => 'SUN-920',
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'quantity' => 1,
        'unit_weight' => 920,
        'unit_price' => null,
    ]);

    $response = get(route('supplier-orders.po-email-copy', $order));

    $response
        ->assertOk()
        ->assertSee('Codes fournisseur: SUN-920')
        ->assertSee('Ref fournisseur: SUN-920')
        ->assertSee('prix a confirmer')
        ->assertSee('Tarifs a confirmer par retour d\'email.')
        ->assertDontSee('0,00 EUR/kg');
});

it('shows unit-based pack quantities correctly in supplier email copy', function () {
    actingAs(User::factory()->create());

    $supplier = Supplier::factory()->create([
        'name' => 'Unit Supplier',
    ]);

    $order = SupplierOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'order_ref' => '2026-TS-1005',
    ]);

    $listing = SupplierListing::factory()->create([
        'supplier_id' => $supplier->id,
        'name' => 'Bouchon spray',
        'supplier_code' => 'CAP-024',
        'unit_of_measure' => 'u',
        'unit_weight' => 24,
    ]);

    SupplierOrderItem::factory()->create([
        'supplier_order_id' => $order->id,
        'supplier_listing_id' => $listing->id,
        'quantity' => 3,
        'unit_weight' => 24,
        'unit_price' => 0.42,
    ]);

    $response = get(route('supplier-orders.po-email-copy', $order));

    $response
        ->assertOk()
        ->assertSee('3 x 24 = 72 u')
        ->assertSee('0,42 EUR | 30,24 EUR');
});
