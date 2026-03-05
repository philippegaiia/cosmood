<?php

namespace App\Filament\Resources\Production\ProductResource\Pages;

use App\Filament\Resources\Production\ProductResource\ProductResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string
    {
        return __('Modifier').' '.$this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => ! $this->record->hasProductionHistory()),
            ForceDeleteAction::make()
                ->visible(fn (): bool => ! $this->record->hasProductionHistory()),
            RestoreAction::make(),
        ];
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('Détail');
    }

    protected function afterSave(): void
    {
        $defaultFormulaId = $this->data['default_formula_id'] ?? null;
        $this->record->setDefaultFormula($defaultFormulaId ? (int) $defaultFormulaId : null);

        $packagingIds = $this->data['packaging_ids'] ?? [];
        $this->record->syncPackaging($packagingIds);
    }
}
