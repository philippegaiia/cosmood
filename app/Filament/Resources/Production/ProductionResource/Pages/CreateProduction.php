<?php

namespace App\Filament\Resources\Production\ProductionResource\Pages;

use App\Filament\Resources\Production\ProductionResource;
use App\Services\Production\PlanningBatchNumberService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateProduction extends CreateRecord
{
    protected static string $resource = ProductionResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['batch_number'] ?? null)) {
            $data['batch_number'] = app(PlanningBatchNumberService::class)->generateNextReference();
        }

        return $data;
    }
}
