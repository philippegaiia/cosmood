<?php

namespace App\Filament\Resources\Production\FormulaResource\Pages;

use App\Filament\Resources\Production\FormulaResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateFormula extends CreateRecord
{
    protected static string $resource = FormulaResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }
}
