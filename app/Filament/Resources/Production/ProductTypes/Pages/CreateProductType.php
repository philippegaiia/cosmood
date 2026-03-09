<?php

namespace App\Filament\Resources\Production\ProductTypes\Pages;

use App\Filament\Resources\Production\ProductTypes\ProductTypeResource;
use App\Services\Production\ProductTypeProductionLineService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateProductType extends CreateRecord
{
    protected static string $resource = ProductTypeResource::class;

    /** @var array<int, int> */
    protected array $normalizedAllowedProductionLineIds = [];

    protected ?int $normalizedDefaultProductionLineId = null;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $selection = app(ProductTypeProductionLineService::class)->normalizeSelection(
            $this->data['allowed_production_line_ids'] ?? [],
            isset($data['default_production_line_id']) ? (int) $data['default_production_line_id'] : null,
        );

        $this->normalizedAllowedProductionLineIds = $selection['allowed_production_line_ids'];
        $this->normalizedDefaultProductionLineId = $selection['default_production_line_id'];

        $data['default_production_line_id'] = $this->normalizedDefaultProductionLineId;

        return $data;
    }

    protected function afterCreate(): void
    {
        app(ProductTypeProductionLineService::class)->sync(
            $this->record,
            $this->normalizedAllowedProductionLineIds,
            $this->normalizedDefaultProductionLineId,
        );
    }
}
