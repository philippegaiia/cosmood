<?php

namespace App\Filament\Resources\Supply\StockMovements\Pages;

use App\Filament\Resources\Supply\StockMovements\StockMovementResource;
use Filament\Resources\Pages\ListRecords;

/**
 * List Stock Movements page.
 *
 * Read-only view of stock movement history.
 */
class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action - movements are created programmatically
        ];
    }
}
