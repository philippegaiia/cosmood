<?php

namespace App\Filament\Resources\Supply\SupplierContactResource\Pages;

use App\Filament\Resources\Supply\SupplierContactResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierContact extends CreateRecord
{
    protected static string $resource = SupplierContactResource::class;

    protected ?string $heading = 'Nouveau Contact Fournisseur';
}
