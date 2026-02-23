<?php

namespace App\Filament\Resources\QcTemplates\Pages;

use App\Filament\Resources\QcTemplates\QcTemplatesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQcTemplates extends ListRecords
{
    protected static string $resource = QcTemplatesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
