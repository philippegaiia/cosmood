<?php

namespace App\Filament\Resources\Production\FormulaResource\Pages;

use App\Filament\Resources\Production\FormulaResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewFormula extends ViewRecord
{
    protected static string $resource = FormulaResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            FormulaResource::makeDuplicateAction(),
            EditAction::make(),
        ];
    }
}
