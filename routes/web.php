<?php

use App\Http\Controllers\Supply\SupplierOrderDocumentController;
use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Services\Production\MasterbatchService;
use App\Services\Production\WaveProcurementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
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

Route::get('/productions/bulk-documents', function (Request $request) {
    $ids = collect(explode(',', (string) $request->query('ids', '')))
        ->map(fn (string $id): int => (int) trim($id))
        ->filter(fn (int $id): bool => $id > 0)
        ->unique()
        ->values();

    $productions = Production::query()
        ->with('product')
        ->whereIn('id', $ids->all())
        ->orderBy('production_date')
        ->orderBy('id')
        ->get();

    return view('production.productions.production-bulk-documents', [
        'productions' => $productions,
    ]);
})->middleware('auth')->name('productions.bulk-documents');

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

Route::middleware('auth')
    ->controller(SupplierOrderDocumentController::class)
    ->group(function (): void {
        Route::get('/supplier-orders/{supplierOrder}/po-print', 'printView')->name('supplier-orders.po-print');
        Route::get('/supplier-orders/{supplierOrder}/po-pdf', 'pdf')->name('supplier-orders.po-pdf');
        Route::get('/supplier-orders/{supplierOrder}/po-email-copy', 'emailCopy')->name('supplier-orders.po-email-copy');
    });
