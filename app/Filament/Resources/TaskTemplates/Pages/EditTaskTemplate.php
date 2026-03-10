<?php

namespace App\Filament\Resources\TaskTemplates\Pages;

use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTaskTemplate extends EditRecord
{
    protected static string $resource = TaskTemplateResource::class;

    /**
     * @var array<int, array{product_type_id?: mixed, is_default?: mixed}>
     */
    protected array $productTypeLinks = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['product_type_links'] = $this->record->getProductTypeLinksForForm();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->productTypeLinks = $data['product_type_links'] ?? [];

        unset($data['product_type_links']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncProductTypeLinks($this->productTypeLinks);
    }
}
