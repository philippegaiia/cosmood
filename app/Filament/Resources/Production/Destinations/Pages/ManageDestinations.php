<?php

namespace App\Filament\Resources\Production\Destinations\Pages;

use App\Filament\Resources\Production\Destinations\DestinationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDestinations extends ManageRecords
{
    protected static string $resource = DestinationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
