<?php

namespace App\Filament\Resources\Production\ProductionWaves\Pages;

use App\Enums\WaveStatus;
use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductionWave extends CreateRecord
{
    protected static string $resource = ProductionWaveResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = WaveStatus::Draft;

        return $data;
    }
}
