<?php

namespace App\Filament\Resources\Production\ProductionLines\Pages;

use App\Filament\Resources\Production\ProductionLines\ProductionLineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductionLines extends ListRecords
{
    protected static string $resource = ProductionLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
