<?php

namespace App\Filament\Resources\Production\ProductResource\Pages;

use App\Filament\Resources\Production\ProductResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabComponent(): Tab
    {
        return Tab::make('Détails')
            ->icon(Heroicon::DocumentText);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent()->columnSpanFull(),
                $this->getRelationManagersContentComponent()->columnSpanFull(),
            ])
            ->columns(1);
    }

    protected function afterSave(): void
    {
        $defaultFormulaId = $this->data['default_formula_id'] ?? null;
        $this->record->setDefaultFormula($defaultFormulaId ? (int) $defaultFormulaId : null);

        $packagingIds = $this->data['packaging_ids'] ?? [];
        $this->record->syncPackaging($packagingIds);
    }
}
