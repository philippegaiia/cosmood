<?php

namespace App\Filament\Resources\QcTemplates\Pages;

use App\Filament\Resources\QcTemplates\QcTemplatesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListQcTemplates extends ListRecords
{
    protected static string $resource = QcTemplatesResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
