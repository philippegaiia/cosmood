<?php

namespace App\Filament\Resources\Production\ProductionResource\RelationManagers;

use App\Models\Production\ProductionTask;
use App\Services\Production\TaskGenerationService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                TextColumn::make('templateItem.name')
                    ->label('Tâche')
                    ->placeholder('Tâche manuelle')
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
                CreateAction::make()
                    ->label('Ajouter tâche manuelle')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom')
                            ->required(),
                        DatePicker::make('scheduled_date')
                            ->label('Date planifiée')
                            ->native(false)
                            ->required(),
                        TextInput::make('duration_minutes')
                            ->label('Durée (minutes)')
                            ->numeric()
                            ->minValue(5)
                            ->default(60)
                            ->required(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3),
                    ])
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'date' => $data['scheduled_date'],
                        'source' => 'manual',
                        'is_finished' => false,
                        'is_manual_schedule' => true,
                        'sequence_order' => null,
                    ]),
            ])
            ->recordActions([
                Action::make('finish')
                    ->label('Terminer')
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (ProductionTask $record): bool => ! $record->is_finished && ! $record->isCancelled() && ! app(TaskGenerationService::class)->isBlockedByDependencies($record))
                    ->action(function (ProductionTask $record): void {
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
                    ->visible(fn (ProductionTask $record): bool => ! $record->is_finished && ! $record->isCancelled() && app(TaskGenerationService::class)->isBlockedByDependencies($record))
                    ->schema([
                        Textarea::make('reason')
                            ->label('Raison du bypass')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (ProductionTask $record, array $data): void {
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
                    ->visible(fn (ProductionTask $record): bool => ! $record->is_finished && ! $record->isCancelled())
                    ->schema([
                        DatePicker::make('scheduled_date')
                            ->label('Nouvelle date')
                            ->native(false)
                            ->required(),
                    ])
                    ->action(function (ProductionTask $record, array $data): void {
                        app(TaskGenerationService::class)->setManualSchedule($record, $data['scheduled_date']);
                    }),
                Action::make('reset_auto')
                    ->label('Retour auto')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (ProductionTask $record): bool => $record->is_manual_schedule && ! $record->is_finished && ! $record->isCancelled())
                    ->action(function (ProductionTask $record): void {
                        app(TaskGenerationService::class)->resetToAutoSchedule($record);
                    }),
                Action::make('cancel')
                    ->label('Annuler')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (ProductionTask $record): bool => ! $record->isCancelled())
                    ->schema([
                        Textarea::make('reason')
                            ->label('Raison')
                            ->required(),
                    ])
                    ->action(function (ProductionTask $record, array $data): void {
                        $record->cancel($data['reason']);
                    }),
            ])
            ->defaultSort('scheduled_date');
    }
}
