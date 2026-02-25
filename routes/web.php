<?php

use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Models\Supply\SupplierOrder;
use App\Services\Production\MasterbatchService;
use App\Services\Production\WaveProcurementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/production-waves/{wave}/procurement-plan/print', function (ProductionWave $wave, WaveProcurementService $service) {
    $lines = $service->getPlanningList($wave);
    $estimatedTotal = round((float) $lines->sum(fn (object $line): float => (float) ($line->estimated_cost ?? 0)), 2);

    return view('production.waves.procurement-plan-print', [
        'wave' => $wave,
        'lines' => $lines,
        'estimatedTotal' => $estimatedTotal,
    ]);
})->middleware('auth')->name('production-waves.procurement-plan.print');

Route::get('/productions/{production}/print-sheet', function (Production $production) {
    $production->load([
        'wave',
        'product',
        'formula',
        'productionItems.ingredient',
        'productionItems.supplierListing',
        'productionItems.supply',
        'productionTasks',
        'productionQcChecks.checkedBy',
    ]);

    $masterbatchService = app(MasterbatchService::class);
    $masterbatchLine = $masterbatchService->getMasterbatchLine($production);
    $masterbatchTraceability = $masterbatchService->getMasterbatchTraceabilityLines($production);

    $displayItems = $production->productionItems
        ->sortBy(fn ($item) => str_pad((string) (int) ($item->phase ?? 0), 4, '0', STR_PAD_LEFT).'-'.str_pad((string) (int) ($item->sort ?? 0), 4, '0', STR_PAD_LEFT))
        ->values();

    if ($masterbatchLine !== null && isset($masterbatchLine['phase'])) {
        $replacedPhase = (string) $masterbatchLine['phase'];
        $displayItems = $displayItems
            ->reject(fn ($item): bool => (string) ($item->phase ?? '') === $replacedPhase)
            ->values();
    }

    return view('production.productions.production-sheet-print', [
        'production' => $production,
        'displayItems' => $displayItems,
        'masterbatchLine' => $masterbatchLine,
        'masterbatchTraceability' => $masterbatchTraceability,
        'isPdf' => false,
    ]);
})->middleware('auth')->name('productions.print-sheet');

Route::get('/productions/{production}/follow-sheet', function (Production $production) {
    $production->load([
        'product',
    ]);

    return view('production.productions.production-follow-sheet-print', [
        'production' => $production,
    ]);
})->middleware('auth')->name('productions.follow-sheet');

Route::get('/productions/{production}/sheet-pdf', function (Production $production) {
    $production->load([
        'wave',
        'product',
        'formula',
        'productionItems.ingredient',
        'productionItems.supplierListing',
        'productionItems.supply',
        'productionTasks',
        'productionQcChecks.checkedBy',
    ]);

    $masterbatchService = app(MasterbatchService::class);
    $masterbatchLine = $masterbatchService->getMasterbatchLine($production);
    $masterbatchTraceability = $masterbatchService->getMasterbatchTraceabilityLines($production);

    $displayItems = $production->productionItems
        ->sortBy(fn ($item) => str_pad((string) (int) ($item->phase ?? 0), 4, '0', STR_PAD_LEFT).'-'.str_pad((string) (int) ($item->sort ?? 0), 4, '0', STR_PAD_LEFT))
        ->values();

    if ($masterbatchLine !== null && isset($masterbatchLine['phase'])) {
        $replacedPhase = (string) $masterbatchLine['phase'];
        $displayItems = $displayItems
            ->reject(fn ($item): bool => (string) ($item->phase ?? '') === $replacedPhase)
            ->values();
    }

    $pdf = Pdf::loadView('production.productions.production-sheet-print', [
        'production' => $production,
        'displayItems' => $displayItems,
        'masterbatchLine' => $masterbatchLine,
        'masterbatchTraceability' => $masterbatchTraceability,
        'isPdf' => true,
    ]);

    $pdfBatchNumber = $production->permanent_batch_number ?: $production->batch_number;

    return $pdf->download('fiche-production-'.$pdfBatchNumber.'.pdf');
})->middleware('auth')->name('productions.sheet-pdf');

$resolveSupplierOrderDocumentData = function (SupplierOrder $supplierOrder): array {
    $supplierOrder->load([
        'supplier',
        'wave',
        'supplier_order_items.supplierListing',
    ]);

    $lines = $supplierOrder->supplier_order_items
        ->sortBy('id')
        ->values();

    $totalAmount = round((float) $lines->sum(function ($item): float {
        $quantity = (float) ($item->quantity ?? 0);
        $unitWeight = (float) ($item->unit_weight ?? 0);
        $unitPrice = (float) ($item->unit_price ?? 0);

        return $quantity * $unitWeight * $unitPrice;
    }), 2);

    $freightCost = (float) ($supplierOrder->freight_cost ?? 0);
    $grandTotal = round($totalAmount + $freightCost, 2);

    return [
        'supplierOrder' => $supplierOrder,
        'lines' => $lines,
        'totalAmount' => $totalAmount,
        'freightCost' => $freightCost,
        'grandTotal' => $grandTotal,
    ];
};

Route::get('/supplier-orders/{supplierOrder}/po-print', function (SupplierOrder $supplierOrder) use ($resolveSupplierOrderDocumentData) {
    return view('supply.supplier-orders.purchase-order-print', $resolveSupplierOrderDocumentData($supplierOrder));
})->middleware('auth')->name('supplier-orders.po-print');

Route::get('/supplier-orders/{supplierOrder}/po-pdf', function (SupplierOrder $supplierOrder) use ($resolveSupplierOrderDocumentData) {
    $data = $resolveSupplierOrderDocumentData($supplierOrder);

    $pdf = Pdf::loadView('supply.supplier-orders.purchase-order-pdf', $data);

    $filename = 'po-'.($supplierOrder->order_ref ?: $supplierOrder->id).'.pdf';

    return $pdf->download($filename);
})->middleware('auth')->name('supplier-orders.po-pdf');

Route::get('/supplier-orders/{supplierOrder}/po-email-copy', function (SupplierOrder $supplierOrder) use ($resolveSupplierOrderDocumentData) {
    $data = $resolveSupplierOrderDocumentData($supplierOrder);

    $orderDate = filled($supplierOrder->order_date)
        ? \Illuminate\Support\Carbon::parse($supplierOrder->order_date)->format('d/m/Y')
        : '-';
    $deliveryDate = filled($supplierOrder->delivery_date)
        ? \Illuminate\Support\Carbon::parse($supplierOrder->delivery_date)->format('d/m/Y')
        : '-';

    $textLines = [];
    $textLines[] = 'Bonjour '.($supplierOrder->supplier?->name ?? '').',';
    $textLines[] = '';
    $textLines[] = 'Veuillez trouver ci-dessous notre bon de commande '.($supplierOrder->order_ref ?? ('PO-'.$supplierOrder->id)).'.';
    $textLines[] = 'Date commande: '.$orderDate;
    $textLines[] = 'Date livraison souhaitee: '.$deliveryDate;
    $textLines[] = '';
    $textLines[] = 'Lignes:';

    foreach ($data['lines'] as $line) {
        $quantity = (float) ($line->quantity ?? 0);
        $unitWeight = (float) ($line->unit_weight ?? 0);
        $unitPrice = (float) ($line->unit_price ?? 0);
        $lineKg = round($quantity * $unitWeight, 3);
        $lineAmount = round($lineKg * $unitPrice, 2);

        $textLines[] = '- '.($line->supplierListing?->name ?? 'Article')
            .' | '.number_format($quantity, 3, ',', ' ').' x '.number_format($unitWeight, 3, ',', ' ')
            .' = '.number_format($lineKg, 3, ',', ' ').' kg'
            .' | '.number_format($unitPrice, 2, ',', ' ').' EUR/kg'
            .' | '.number_format($lineAmount, 2, ',', ' ').' EUR';
    }

    $textLines[] = '';
    $textLines[] = 'Total lignes: '.number_format((float) $data['totalAmount'], 2, ',', ' ').' EUR';
    $textLines[] = 'Transport: '.number_format((float) $data['freightCost'], 2, ',', ' ').' EUR';
    $textLines[] = 'Total commande: '.number_format((float) $data['grandTotal'], 2, ',', ' ').' EUR';
    $textLines[] = '';
    $textLines[] = 'Cordialement,';
    $textLines[] = config('app.name');

    $emailSubject = 'PO '.($supplierOrder->order_ref ?? ('PO-'.$supplierOrder->id)).' - '.($supplierOrder->supplier?->name ?? config('app.name'));

    return view('supply.supplier-orders.purchase-order-email-copy', [
        'supplierOrder' => $supplierOrder,
        'emailSubject' => $emailSubject,
        'emailText' => implode(PHP_EOL, $textLines),
    ]);
})->middleware('auth')->name('supplier-orders.po-email-copy');
