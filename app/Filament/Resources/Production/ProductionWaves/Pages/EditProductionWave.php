<?php

namespace App\Filament\Resources\Production\ProductionWaves\Pages;

use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use App\Services\Production\WaveDeletionService;
use App\Services\Production\WaveProductionPlanningService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProductionWave extends EditRecord
{
    protected static string $resource = ProductionWaveResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $advisoryMessage = $this->record->getStatusAdvisoryMessage();

        if (! $advisoryMessage) {
            return;
        }

        Notification::make()
            ->title(__('Synchronisation suggérée'))
            ->body($advisoryMessage)
            ->warning()
            ->send();
    }

    protected function afterSave(): void
    {
        if (! $this->record->wasChanged('planned_start_date')) {
            return;
        }

        $this->record->refresh();

        if (! $this->record->planned_start_date) {
            return;
        }

        if ($this->record->isInProgress() || $this->record->isCompleted() || $this->record->isCancelled()) {
            Notification::make()
                ->title(__('Replanification bloquée'))
                ->body(__('Une vague en cours, terminée ou annulée ne peut pas être replanifiée.'))
                ->warning()
                ->send();

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
            Action::make('hardDeleteWave')
                ->label('Supprimer définitivement')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => ! $this->record->isInProgress() && ! $this->record->isCompleted() && (auth()->user()?->canDeleteWaves() ?? false))
                ->authorize(fn (): bool => auth()->user()?->canDeleteWaves() ?? false)
                ->modalDescription('Supprime définitivement la vague et ses productions. Les allocations doivent être désallouées et les engagements PO retirés manuellement.')
                ->action(function (): void {
                    try {
                        app(WaveDeletionService::class)->hardDeleteWaveWithProductions($this->record);

                        Notification::make()
                            ->title(__('Vague supprimée définitivement'))
                            ->success()
                            ->send();

                        $this->redirect(ProductionWaveResource::getUrl('index'));
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()
                            ->title(__('Suppression impossible'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
