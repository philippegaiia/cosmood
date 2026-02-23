<?php

namespace App\Filament\Resources\Production\ProductionWaves\Pages;

use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProductionWave extends EditRecord
{
    protected static string $resource = ProductionWaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
