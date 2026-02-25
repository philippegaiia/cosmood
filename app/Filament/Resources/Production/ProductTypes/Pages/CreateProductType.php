<?php

namespace App\Filament\Resources\Production\ProductTypes\Pages;

use App\Filament\Resources\Production\ProductTypes\ProductTypeResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateProductType extends CreateRecord
{
    protected static string $resource = ProductTypeResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }
}
