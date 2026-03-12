<?php

namespace App\Filament\Resources\Production\ProductTypes\Pages;

use App\Filament\Resources\Production\ProductTypes\ProductTypeResource;
use App\Services\Production\ProductTypeProductionLineService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditProductType extends EditRecord
{
    protected static string $resource = ProductTypeResource::class;

    /** @var array<int, int> */
    protected array $normalizedAllowedProductionLineIds = [];

    protected ?int $normalizedDefaultProductionLineId = null;

    /**
     * @var array{
     *     allowed_production_line_ids?: array<int, int>,
     *     default_production_line_id?: int|null,
     *     migrated_planned_count?: int,
     *     confirmed_conflict_count?: int,
     *     confirmed_conflict_line_names?: array<int, string>
     * }
     */
    protected array $productionLineSyncSummary = [];

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    /**
     * Capture and normalize the allowed-lines selection before the record is saved.
     *
     * Note: allowed_production_line_ids is read from $this->data (Livewire form state) rather
     * than $data (the save payload) because the field is marked dehydrated(false) and is
     * therefore intentionally absent from the serialized save data.
     *
     * The normalized values are stored on instance properties so afterSave() can use them
     * without re-reading form state after the record has already been written.
     */
    protected function mutateFormDataBeforeSave(array $data): array
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

    /**
     * Sync the allowed-lines pivot and surface any confirmed-production conflicts.
     *
     * Runs after the record is saved so the pivot sync operates on a persisted product type ID.
     * Re-fills the form with fresh relationship data to keep the UI consistent after the save.
     * Sends a warning notification when confirmed productions still reference a removed line
     * (those are not auto-migrated — the planner must handle them manually).
     */
    protected function afterSave(): void
    {
        $this->productionLineSyncSummary = app(ProductTypeProductionLineService::class)->sync(
            $this->record,
            $this->normalizedAllowedProductionLineIds,
            $this->normalizedDefaultProductionLineId,
        );

        $this->record->refresh()->load('allowedProductionLines');

        $this->form->fill([
            ...$this->data,
            'default_production_line_id' => $this->record->default_production_line_id,
            'allowed_production_line_ids' => $this->record->allowedProductionLines->modelKeys(),
        ]);

        if (($this->productionLineSyncSummary['confirmed_conflict_count'] ?? 0) < 1) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(__('Productions confirmées à replanifier'))
            ->body(__(':count production(s) confirmée(s) restent affectée(s) à :lines. Vérifiez la planification.', [
                'count' => $this->productionLineSyncSummary['confirmed_conflict_count'],
                'lines' => implode(', ', $this->productionLineSyncSummary['confirmed_conflict_line_names'] ?? []),
            ]))
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
