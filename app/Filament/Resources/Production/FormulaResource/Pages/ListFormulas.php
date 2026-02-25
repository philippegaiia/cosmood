<?php

namespace App\Filament\Resources\Production\FormulaResource\Pages;

use App\Filament\Resources\Production\FormulaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListFormulas extends ListRecords
{
    protected static string $resource = FormulaResource::class;

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
