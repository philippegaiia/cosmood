<?php

namespace App\Filament\Resources\QcTemplates\Pages;

use App\Filament\Resources\QcTemplates\QcTemplatesResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditQcTemplates extends EditRecord
{
    protected static string $resource = QcTemplatesResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
