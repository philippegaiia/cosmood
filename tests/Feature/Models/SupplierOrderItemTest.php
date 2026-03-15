<?php

use App\Models\Production\Production;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('SupplierOrderItem Model', function () {
    it('can be created with factory', function () {
        $item = SupplierOrderItem::factory()->create();

        expect($item)
            ->toBeInstanceOf(SupplierOrderItem::class)
            ->and((float) $item->quantity)->toBeGreaterThan(0);
    });

    it('belongs to a supplier order', function () {
        $order = SupplierOrder::factory()->create();
        $item = SupplierOrderItem::factory()->create(['supplier_order_id' => $order->id]);

        expect($item->supplierOrder->id)->toBe($order->id);
    });

    it('belongs to a supplier listing', function () {
        $listing = SupplierListing::factory()->create();
        $item = SupplierOrderItem::factory()->create(['supplier_listing_id' => $listing->id]);

        expect($item->supplierListing->id)->toBe($listing->id);
    });

    it('can be allocated to a production', function () {
        $production = Production::factory()->create();
        $item = SupplierOrderItem::factory()->allocated($production, 10.0)->create();

        expect($item->allocatedToProduction->id)->toBe($production->id)
            ->and((float) $item->allocated_quantity)->toBe(10.0);
    });

    it('calculates remaining quantity', function () {
        $item = SupplierOrderItem::factory()->create([
            'quantity' => 50.0,
            'allocated_quantity' => 20.0,
        ]);

        expect((float) $item->getRemainingQuantity())->toBe(30.0);
    });

    it('can be marked as in supplies', function () {
        $item = SupplierOrderItem::factory()->inSupplies()->create();

        expect($item->isInSupplies())->toBeTrue()
            ->and($item->is_in_supplies)->toBe('Stock');
    });

    it('rejects commitments above ordered quantity', function () {
        expect(function (): void {
            SupplierOrderItem::factory()->create([
                'quantity' => 1,
                'unit_weight' => 10,
                'committed_quantity_kg' => 12,
            ]);
        })->toThrow(InvalidArgumentException::class, 'ne peut pas dépasser la quantité commandée');
    });

    it('allows commitments equal to ordered quantity', function () {
        $item = SupplierOrderItem::factory()->create([
            'quantity' => 2,
            'unit_weight' => 15,
            'committed_quantity_kg' => 30,
        ]);

        expect((float) $item->committed_quantity_kg)->toBe(30.0)
            ->and((float) $item->getOrderedQuantityKg())->toBe(30.0);
    });

    it('normalizes null commitment to zero on save', function () {
        $item = SupplierOrderItem::factory()->create([
            'quantity' => 1,
            'unit_weight' => 12,
            'committed_quantity_kg' => null,
        ]);

        expect((float) $item->fresh()->committed_quantity_kg)->toBe(0.0);
    });

    it('allows duplicate supplier batch numbers across order items', function () {
        $firstItem = SupplierOrderItem::factory()->create([
            'batch_number' => 'COCOCAU',
        ]);

        $secondItem = SupplierOrderItem::factory()->create([
            'batch_number' => 'COCOCAU',
        ]);

        expect($firstItem->batch_number)->toBe('COCOCAU')
            ->and($secondItem->batch_number)->toBe('COCOCAU');
    });

    it('rejects negative ordered quantity', function () {
        expect(function (): void {
            SupplierOrderItem::factory()->create([
                'quantity' => -3,
                'unit_weight' => 25,
                'committed_quantity_kg' => 0,
            ]);
        })->toThrow(InvalidArgumentException::class, 'quantité commandée doit être supérieure à zéro');
    });

    it('rejects decimal ordered quantity for unit-based supplier listings', function () {
        $ingredient = Ingredient::factory()->unitBased()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'unit_of_measure' => 'u',
            'unit_weight' => 0,
        ]);

        expect(function () use ($listing): void {
            SupplierOrderItem::factory()->create([
                'supplier_listing_id' => $listing->id,
                'quantity' => 3.5,
                'unit_weight' => 0,
            ]);
        })->toThrow(InvalidArgumentException::class, 'nombre entier');
    });

    it('rejects decimal committed quantity for unit-based supplier listings', function () {
        $ingredient = Ingredient::factory()->unitBased()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'unit_of_measure' => 'u',
            'unit_weight' => 0,
        ]);

        expect(function () use ($listing): void {
            SupplierOrderItem::factory()->create([
                'supplier_listing_id' => $listing->id,
                'quantity' => 5,
                'unit_weight' => 0,
                'committed_quantity_kg' => 2.5,
            ]);
        })->toThrow(InvalidArgumentException::class, 'nombre entier');
    });

    it('calculates ordered quantity from quantity times uom content for unit-based listings', function () {
        $ingredient = Ingredient::factory()->unitBased()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'unit_of_measure' => 'u',
            'unit_weight' => 24,
        ]);

        $item = SupplierOrderItem::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity' => 3,
            'unit_weight' => 24,
            'committed_quantity_kg' => 72,
        ]);

        expect($item->getDisplayUnit())->toBe('u')
            ->and((float) $item->getOrderedQuantityKg())->toBe(72.0);
    });

    it('deletes supplier order items permanently before stock transfer', function () {
        $item = SupplierOrderItem::factory()->create();
        $itemId = $item->id;

        $item->delete();

        expect(SupplierOrderItem::query()->find($itemId))->toBeNull();
    });

    it('blocks deleting supplier order items once stock exists', function () {
        $listing = SupplierListing::factory()->create();
        $item = SupplierOrderItem::factory()->create([
            'supplier_listing_id' => $listing->id,
            'is_in_supplies' => 'Stock',
            'moved_to_stock_at' => now(),
        ]);

        Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'supplier_order_item_id' => $item->id,
        ]);

        expect(fn () => $item->delete())
            ->toThrow(InvalidArgumentException::class, 'déjà passé en stock');

        expect(SupplierOrderItem::query()->find($item->id))->not->toBeNull();
    });

    it('blocks editing locked procurement fields once stock exists', function () {
        $listing = SupplierListing::factory()->create();
        $item = SupplierOrderItem::factory()->create([
            'supplier_listing_id' => $listing->id,
            'quantity' => 5,
            'is_in_supplies' => 'Stock',
            'moved_to_stock_at' => now(),
        ]);

        Supply::factory()->create([
            'supplier_listing_id' => $listing->id,
            'supplier_order_item_id' => $item->id,
        ]);

        expect(fn () => $item->update([
            'quantity' => 6,
        ]))->toThrow(InvalidArgumentException::class, 'déjà passée en stock');

        expect((float) $item->fresh()->quantity)->toBe(5.0);
    });
});
