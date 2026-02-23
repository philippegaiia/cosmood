<?php

namespace App\Filament\Resources\Production\ProductionWaves\Pages;

use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductionWaves extends ListRecords
{
    protected static string $resource = ProductionWaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
