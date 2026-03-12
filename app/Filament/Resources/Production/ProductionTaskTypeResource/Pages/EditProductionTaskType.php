<?php

namespace App\Filament\Resources\Production\ProductionTaskTypeResource\Pages;

use App\Filament\Resources\Production\ProductionTaskTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProductionTaskType extends EditRecord
{
    protected static string $resource = ProductionTaskTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
