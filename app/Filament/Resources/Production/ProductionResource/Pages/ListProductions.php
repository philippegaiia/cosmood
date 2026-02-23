<?php

namespace App\Filament\Resources\Production\ProductionResource\Pages;

use App\Filament\Resources\Production\ProductionResource;
use App\Models\Production\Production;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductions extends ListRecords
{
    protected static string $resource = ProductionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
            /*  ->after(function (Production $record){
                dd($record);
            })*/,
        ];
    }
}
