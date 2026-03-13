<?php

namespace App\Filament\Resources\Production\ProductionResource\RelationManagers;

use App\Enums\ProductionStatus;
use App\Models\Production\ProductionTask;
use App\Services\Production\TaskGenerationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class ProductionTasksRelationManager extends RelationManager
{
    protected static string $relationship = 'productionTasks';

    protected static ?string $title = 'Tâches';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Tâche'))
                    ->placeholder(__('Tâche manuelle'))
                    ->searchable(),
                TextColumn::make('workflow_status')
                    ->label('Statut')
                    ->state(function (ProductionTask $record): string {
                        if ($record->isCancelled()) {
                            return 'Annulée';
                        }

                        if ($record->is_finished) {
                            return 'Terminée';
                        }

                        if ($record->scheduled_date && Carbon::parse($record->scheduled_date)->isFuture()) {
                            return 'Non démarrée';
                        }

                        return 'Démarrée';
                    })
                    ->badge()
                    ->color(function (ProductionTask $record): string|array|null {
                        if ($record->isCancelled()) {
                            return 'gray';
                        }

                        if ($record->is_finished) {
                            return 'success';
                        }

                        if ($record->scheduled_date && Carbon::parse($record->scheduled_date)->isFuture()) {
                            return 'warning';
                        }

                        return [
                            50 => '#fff7ed',
                            100 => '#ffedd5',
                            200 => '#fed7aa',
                            300 => '#fdba74',
                            400 => '#fb923c',
                            500 => '#f97316',
                            600 => '#ea580c',
                            700 => '#c2410c',
                            800 => '#9a3412',
                            900 => '#7c2d12',
                            950 => '#431407',
                        ];
                    }),
                TextColumn::make('scheduled_date')
                    ->label('Date planifiée')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('duration_minutes')
                    ->label('Durée (min)')
                    ->numeric(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge(),
                IconColumn::make('is_manual_schedule')
                    ->label('Planning manuel')
                    ->boolean(),
                IconColumn::make('is_finished')
                    ->label('Terminée')
                    ->boolean(),
                IconColumn::make('dependency_bypassed_at')
                    ->label('Bypass')
                    ->boolean()
                    ->state(fn (ProductionTask $record): bool => $record->dependency_bypassed_at !== null),
                TextColumn::make('dependencyBypassedBy.name')
                    ->label('Bypass par')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('dependency_bypassed_at')
                    ->label('Bypass le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('dependency_bypass_reason')
                    ->label('Raison bypass')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancelled_at')
                    ->label('Annulée le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancelled_reason')
                    ->label('Raison annulation')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                // No manual task creation - all tasks are generated from templates
            ])
            ->emptyStateDescription(__('Toutes les tâches sont générées automatiquement à partir des modèles de tâches associés au type de produit. Pour ajouter une tâche manquante, configurez un modèle de tâche ou ajoutez une note à la production.'))
            ->recordActions([
                Action::make('finish')
                    ->label('Terminer')
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (ProductionTask $record): bool => $this->canFinishTask($record))
                    ->action(function (ProductionTask $record): void {
                        if (! $this->canExecuteTaskOnCurrentProduction($record)) {
                            Notification::make()
                                ->warning()
                                ->title(__('Exécution non autorisée'))
                                ->body(__('Les tâches ne peuvent être terminées que sur une production en cours.'))
                                ->send();

                            return;
                        }

                        if ($this->isTaskScheduledInFuture($record)) {
                            Notification::make()
                                ->warning()
                                ->title(__('Tâche planifiée plus tard'))
                                ->body(__('Impossible de terminer une tâche prévue dans le futur sans replanification préalable.'))
                                ->send();

                            return;
                        }

                        try {
                            app(TaskGenerationService::class)->markTaskAsFinished($record);
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('force_finish')
                    ->label('Forcer terminer')
                    ->color('warning')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->visible(fn (ProductionTask $record): bool => $this->canForceFinishTask($record))
                    ->schema([
                        Textarea::make('reason')
                            ->label('Raison du bypass')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (ProductionTask $record, array $data): void {
                        if (! $this->canExecuteTaskOnCurrentProduction($record)) {
                            Notification::make()
                                ->warning()
                                ->title(__('Exécution non autorisée'))
                                ->body(__('Les tâches ne peuvent être forcées que sur une production en cours.'))
                                ->send();

                            return;
                        }

                        if (! (Auth::user()?->canManageProductionPlanning() ?? false)) {
                            Notification::make()
                                ->warning()
                                ->title(__('Permission insuffisante'))
                                ->body(__('Seuls les profils planification peuvent forcer une tâche.'))
                                ->send();

                            return;
                        }

                        if ($this->isTaskScheduledInFuture($record)) {
                            Notification::make()
                                ->warning()
                                ->title(__('Tâche planifiée plus tard'))
                                ->body(__('Impossible de forcer une tâche prévue dans le futur sans replanification préalable.'))
                                ->send();

                            return;
                        }

                        $user = Auth::user();

                        if (! $user) {
                            return;
                        }

                        try {
                            app(TaskGenerationService::class)->forceFinishTask($record, $user, $data['reason']);
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('reschedule')
                    ->label('Planifier')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->visible(fn (ProductionTask $record): bool => $this->canManageTaskPlanning($record))
                    ->schema([
                        DatePicker::make('scheduled_date')
                            ->label('Nouvelle date')
                            ->native(false)
                            ->required(),
                    ])
                    ->action(function (ProductionTask $record, array $data): void {
                        if (! $this->canManageTaskPlanning($record)) {
                            Notification::make()
                                ->warning()
                                ->title(__('Permission insuffisante'))
                                ->body(__('Seuls les profils planification peuvent replanifier les tâches actives.'))
                                ->send();

                            return;
                        }

                        app(TaskGenerationService::class)->setManualSchedule($record, $data['scheduled_date']);
                    }),
                Action::make('reset_auto')
                    ->label('Retour auto')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (ProductionTask $record): bool => $record->is_manual_schedule && $this->canManageTaskPlanning($record))
                    ->action(function (ProductionTask $record): void {
                        if (! $this->canManageTaskPlanning($record)) {
                            Notification::make()
                                ->warning()
                                ->title(__('Permission insuffisante'))
                                ->body(__('Seuls les profils planification peuvent restaurer le planning automatique.'))
                                ->send();

                            return;
                        }

                        app(TaskGenerationService::class)->resetToAutoSchedule($record);
                    }),
                Action::make('cancel')
                    ->label('Annuler')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (ProductionTask $record): bool => ! $record->isCancelled() && $this->canManageTaskPlanning($record))
                    ->schema([
                        Textarea::make('reason')
                            ->label('Raison')
                            ->required(),
                    ])
                    ->action(function (ProductionTask $record, array $data): void {
                        if (! $this->canManageTaskPlanning($record)) {
                            Notification::make()
                                ->warning()
                                ->title(__('Permission insuffisante'))
                                ->body(__('Seuls les profils planification peuvent annuler une tâche.'))
                                ->send();

                            return;
                        }

                        $record->cancel($data['reason']);
                    }),
            ])
            ->defaultSort('scheduled_date');
    }

    private function canFinishTask(ProductionTask $record): bool
    {
        return $this->canExecuteTaskOnCurrentProduction($record)
            && ! $this->isTaskScheduledInFuture($record)
            && ! app(TaskGenerationService::class)->isBlockedByDependencies($record);
    }

    private function canForceFinishTask(ProductionTask $record): bool
    {
        return $this->canExecuteTaskOnCurrentProduction($record)
            && ! $this->isTaskScheduledInFuture($record)
            && (Auth::user()?->canManageProductionPlanning() ?? false)
            && app(TaskGenerationService::class)->isBlockedByDependencies($record);
    }

    private function canManageTaskPlanning(ProductionTask $record): bool
    {
        return ! $record->is_finished
            && ! $record->isCancelled()
            && in_array($this->getOwnerRecord()->status, [
                ProductionStatus::Planned,
                ProductionStatus::Confirmed,
                ProductionStatus::Ongoing,
            ], true)
            && (Auth::user()?->canManageProductionPlanning() ?? false);
    }

    private function canExecuteTaskOnCurrentProduction(ProductionTask $record): bool
    {
        return ! $record->is_finished
            && ! $record->isCancelled()
            && $this->getOwnerRecord()->status === ProductionStatus::Ongoing
            && (Auth::user()?->canStartProductionRuns() ?? false);
    }

    private function isTaskScheduledInFuture(ProductionTask $record): bool
    {
        return $record->scheduled_date !== null
            && Carbon::parse($record->scheduled_date)->isFuture();
    }
}
