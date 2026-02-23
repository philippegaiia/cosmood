<?php

namespace App\Filament\Resources\QcTemplates\Pages;

use App\Filament\Resources\QcTemplates\QcTemplatesResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditQcTemplates extends EditRecord
{
    protected static string $resource = QcTemplatesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
