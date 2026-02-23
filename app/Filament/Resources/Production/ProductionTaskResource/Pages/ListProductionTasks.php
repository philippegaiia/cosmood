<?php

namespace App\Filament\Resources\Production\ProductionTaskResource\Pages;

use App\Filament\Resources\Production\ProductionTaskResource;
use Filament\Resources\Pages\ListRecords;

class ListProductionTasks extends ListRecords
{
    protected static string $resource = ProductionTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
