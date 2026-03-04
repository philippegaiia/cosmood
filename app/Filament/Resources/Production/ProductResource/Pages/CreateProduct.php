<?php

namespace App\Filament\Resources\Production\ProductResource\Pages;

use App\Filament\Resources\Production\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function afterCreate(): void
    {
        $defaultFormulaId = $this->data['default_formula_id'] ?? null;
        if ($defaultFormulaId) {
            $this->record->setDefaultFormula((int) $defaultFormulaId);
        }
    }
}
