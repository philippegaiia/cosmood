<?php

namespace App\Filament\Resources\Production\ProductionWaves\Pages;

use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use App\Services\Production\WaveProductionPlanningService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProductionWave extends EditRecord
{
    protected static string $resource = ProductionWaveResource::class;

    protected function afterSave(): void
    {
        if (! $this->record->wasChanged('planned_start_date')) {
            return;
        }

        $this->record->refresh();

        if (! $this->record->planned_start_date) {
            return;
        }

        $summary = app(WaveProductionPlanningService::class)->rescheduleWaveProductions(
            wave: $this->record,
            startDate: $this->record->planned_start_date,
            skipWeekends: true,
            skipHolidays: true,
            fallbackDailyCapacity: 4,
        );

        if ($summary['planned_count'] <= 0) {
            Notification::make()
                ->title(__('Aucune production à replanifier'))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('Planification recalculée'))
            ->body(__('Les dates des batchs ont été recalculées depuis le :date.', [
                'date' => (string) ($summary['planned_start_date'] ?? ''),
            ]))
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
