<?php

namespace App\Filament\Resources\Supply\SupplierListingResource\Pages;

use App\Filament\Resources\Supply\SupplierListingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplierListing extends EditRecord
{
    protected static string $resource = SupplierListingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
