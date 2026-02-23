<?php

namespace App\Filament\Resources\Supply\SupplierContactResource\Pages;

use App\Filament\Resources\Supply\SupplierContactResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplierContact extends EditRecord
{
    protected static string $resource = SupplierContactResource::class;

    protected ?string $heading = 'Editer Contact Fournisseur';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
