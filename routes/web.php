<?php

use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
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

    return $pdf->download('fiche-production-'.$production->batch_number.'.pdf');
})->middleware('auth')->name('productions.sheet-pdf');
