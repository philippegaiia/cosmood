<?php

namespace App\Filament\Resources\Production\ProductionResource\Pages;

use App\Filament\Resources\Production\ProductionResource;
use App\Services\Production\PlanningBatchNumberService;
use Filament\Resources\Pages\CreateRecord;

class CreateProduction extends CreateRecord
{
    protected static string $resource = ProductionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['batch_number'] ?? null)) {
            $data['batch_number'] = app(PlanningBatchNumberService::class)->generateNextReference();
        }

        return $data;
    }
}
