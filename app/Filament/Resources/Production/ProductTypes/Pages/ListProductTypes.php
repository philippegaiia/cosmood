<?php

namespace App\Filament\Resources\Production\ProductTypes\Pages;

use App\Filament\Resources\Production\ProductTypes\ProductTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListProductTypes extends ListRecords
{
    protected static string $resource = ProductTypeResource::class;

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
