<?php

namespace App\Filament\Resources\Supply\SupplierResource\Pages;

use App\Filament\Resources\Supply\SupplierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;

    protected ?string $heading = 'Nouveau Fournisseur';
}
