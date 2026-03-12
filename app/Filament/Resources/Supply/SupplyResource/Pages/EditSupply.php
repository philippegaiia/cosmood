<?php

namespace App\Filament\Resources\Supply\SupplyResource\Pages;

use App\Filament\Resources\Supply\SupplyResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditSupply extends EditRecord
{
    protected static string $resource = SupplyResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
