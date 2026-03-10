<?php

namespace App\Filament\Resources\TaskTemplates\Pages;

use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaskTemplate extends CreateRecord
{
    protected static string $resource = TaskTemplateResource::class;

    /**
     * @var array<int, array{product_type_id?: mixed, is_default?: mixed}>
     */
    protected array $productTypeLinks = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->productTypeLinks = $data['product_type_links'] ?? [];

        unset($data['product_type_links']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncProductTypeLinks($this->productTypeLinks);
    }
}
