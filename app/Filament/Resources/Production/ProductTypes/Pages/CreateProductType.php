<?php

namespace App\Filament\Resources\Production\ProductTypes\Pages;

use App\Filament\Resources\Production\ProductTypes\ProductTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductType extends CreateRecord
{
    protected static string $resource = ProductTypeResource::class;
}
