<?php

namespace App\Filament\Resources\QcTemplates\Pages;

use App\Filament\Resources\QcTemplates\QcTemplatesResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateQcTemplates extends CreateRecord
{
    protected static string $resource = QcTemplatesResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }
}
