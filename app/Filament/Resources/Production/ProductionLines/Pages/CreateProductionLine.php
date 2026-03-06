<?php

namespace App\Filament\Resources\Production\ProductionLines\Pages;

use App\Filament\Resources\Production\ProductionLines\ProductionLineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductionLine extends CreateRecord
{
    protected static string $resource = ProductionLineResource::class;
}
