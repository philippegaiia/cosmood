<?php

namespace App\Filament\Resources\Supply\SupplierResource\Pages;

use App\Filament\Resources\Supply\SupplierResource;
use Filament\Resources\Pages\viewRecord;

class ViewSupplier extends viewRecord
{
    protected static string $resource = SupplierResource::class;

    protected ?string $heading = 'Détails Fournisseur';
}
