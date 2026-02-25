<?php

namespace App\Filament\Resources\Supply\SupplyResource\Pages;

use App\Filament\Resources\Supply\SupplyResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewSupply extends ViewRecord
{
    protected static string $resource = SupplyResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
