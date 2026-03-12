<?php

namespace App\Filament\Resources\Production\ProductionWaves\Tables;

use App\Models\Production\ProductionWave;
use App\Services\Production\WaveDeletionService;
use App\Services\Production\WaveProcurementService;
use App\Services\Production\WaveProductionPlanningService;
use App\Services\Production\WaveRequirementStatusService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class ProductionWavesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('coverage_signal')
                    ->label(__('Couverture appro'))
                    ->state(fn (ProductionWave $record): string => $record->getCoverageSignalLabel())
                    ->badge()
                    ->color(fn (ProductionWave $record): string => $record->getCoverageSignalColor())
                    ->tooltip(fn (ProductionWave $record): string => $record->getCoverageSignalTooltip()),
                TextColumn::make('status_sync_advisory')
                    ->label('Alerte flux')
                    ->state(fn (ProductionWave $record): ?string => $record->getStatusAdvisoryMessage())
                    ->badge()
                    ->color('warning')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('productions_count')
                    ->label('Productions')
                    ->counts('productions')
                    ->badge(),
                TextColumn::make('planned_start_date')
                    ->label('Début prévu')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('planned_end_date')
                    ->label('Fin prévue')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('approvedBy.name')
                    ->label('Approuvé par')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approved_at')
                    ->label('Approuvé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completed_at')
                    ->label('Terminé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approuver')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (ProductionWave $record): bool => $record->isDraft() && (auth()->user()?->canManageWaveLifecycle() ?? false))
                    ->authorize(fn (): bool => auth()->user()?->canManageWaveLifecycle() ?? false)
                    ->action(function (ProductionWave $record): void {
                        $user = Auth::user();

                        if (! $user) {
                            return;
                        }

                        $plannedStartDate = $record->planned_start_date;
                        $plannedEndDate = $record->planned_end_date;

                        $record->approve(
                            $user,
                            is_string($plannedStartDate) ? Carbon::parse($plannedStartDate) : $plannedStartDate,
                            is_string($plannedEndDate) ? Carbon::parse($plannedEndDate) : $plannedEndDate,
                        );
                    }),
                Action::make('start')
                    ->label('Démarrer')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (ProductionWave $record): bool => $record->isApproved() && (auth()->user()?->canManageWaveLifecycle() ?? false))
                    ->authorize(fn (): bool => auth()->user()?->canManageWaveLifecycle() ?? false)
                    ->action(function (ProductionWave $record): void {
                        $record->start();
                    }),
                Action::make('complete')
                    ->label('Terminer')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ProductionWave $record): bool => $record->isInProgress() && (auth()->user()?->canManageWaveLifecycle() ?? false))
                    ->authorize(fn (): bool => auth()->user()?->canManageWaveLifecycle() ?? false)
                    ->disabled(fn (ProductionWave $record): bool => $record->hasNonTerminalProductions())
                    ->tooltip(fn (ProductionWave $record): ?string => $record->hasNonTerminalProductions()
                        ? __('Toutes les productions liées doivent être terminées ou annulées pour clôturer la vague.')
                        : null)
                    ->action(function (ProductionWave $record): void {
                        try {
                            $record->complete();
                        } catch (\InvalidArgumentException $exception) {
                            Notification::make()
                                ->title(__('Clôture impossible'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('cancel')
                    ->label('Annuler')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ProductionWave $record): bool => ! $record->isCancelled() && ! $record->isCompleted() && (auth()->user()?->canManageWaveLifecycle() ?? false))
                    ->authorize(fn (): bool => auth()->user()?->canManageWaveLifecycle() ?? false)
                    ->action(function (ProductionWave $record): void {
                        $record->cancel();
                    }),
                Action::make('hardDeleteWave')
                    ->label('Supprimer définitivement')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ProductionWave $record): bool => ! $record->isInProgress() && ! $record->isCompleted() && (auth()->user()?->canDeleteWaves() ?? false))
                    ->authorize(fn (): bool => auth()->user()?->canDeleteWaves() ?? false)
                    ->modalDescription('Supprime définitivement la vague et ses productions. Les allocations doivent être désallouées et les engagements PO retirés manuellement.')
                    ->action(function (ProductionWave $record): void {
                        try {
                            app(WaveDeletionService::class)->hardDeleteWaveWithProductions($record);

                            Notification::make()
                                ->title('Vague supprimée définitivement')
                                ->success()
                                ->send();
                        } catch (\InvalidArgumentException $exception) {
                            Notification::make()
                                ->title('Suppression impossible')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('replanWave')
                    ->label('Replanifier')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->color('info')
                    ->visible(fn (ProductionWave $record): bool => ! $record->isInProgress() && ! $record->isCancelled() && ! $record->isCompleted() && (int) ($record->productions_count ?? 0) > 0 && (auth()->user()?->canManageProductionPlanning() ?? false))
                    ->authorize(fn (): bool => auth()->user()?->canManageProductionPlanning() ?? false)
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Nouveau départ')
                            ->native(false)
                            ->required()
                            ->default(fn (ProductionWave $record): string => $record->planned_start_date?->toDateString() ?? now()->toDateString()),
                        TextInput::make('fallback_daily_capacity')
                            ->label('Capacité / jour sans ligne')
                            ->numeric()
                            ->minValue(1)
                            ->default(4)
                            ->required(),
                        Toggle::make('skip_weekends')
                            ->label('Ignorer weekends')
                            ->default(true),
                        Toggle::make('skip_holidays')
                            ->label('Ignorer jours fériés')
                            ->default(true),
                    ])
                    ->action(function (ProductionWave $record, array $data): void {
                        try {
                            $summary = app(WaveProductionPlanningService::class)->rescheduleWaveProductions(
                                wave: $record,
                                startDate: (string) $data['start_date'],
                                skipWeekends: (bool) ($data['skip_weekends'] ?? true),
                                skipHolidays: (bool) ($data['skip_holidays'] ?? true),
                                fallbackDailyCapacity: max(1, (int) ($data['fallback_daily_capacity'] ?? 4)),
                            );
                        } catch (\InvalidArgumentException $exception) {
                            Notification::make()
                                ->title(__('Replanification impossible'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($summary['planned_count'] === 0) {
                            Notification::make()
                                ->title('Aucune production replanifiée')
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Vague replanifiée')
                            ->body(sprintf(
                                '%d batch(es) replanifiés du %s au %s.',
                                (int) $summary['planned_count'],
                                (string) ($summary['planned_start_date'] ?? '-'),
                                (string) ($summary['planned_end_date'] ?? '-'),
                            ))
                            ->success()
                            ->send();
                    }),
                Action::make('procurementPlan')
                    ->label('Plan achats')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->color('gray')
                    ->modalHeading(fn (ProductionWave $record): string => 'Plan achats - '.$record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer')
                    ->schema([
                        RepeatableEntry::make('planning')
                            ->label('Besoins agrégés')
                            ->state(fn (ProductionWave $record): array => app(WaveProcurementService::class)
                                ->getPlanningList($record)
                                ->map(fn (object $line): array => [
                                    'ingredient' => (string) ($line->ingredient_name ?? '-'),
                                    'to_order' => number_format((float) $line->to_order_quantity, 3, ',', ' ').' kg',
                                    'ordered' => number_format((float) $line->ordered_quantity, 3, ',', ' ').' kg',
                                    'stock' => number_format((float) $line->stock_advisory, 3, ',', ' ').' kg',
                                    'shortage' => number_format((float) $line->advisory_shortage, 3, ',', ' ').' kg',
                                    'last_price' => (float) $line->ingredient_price > 0
                                        ? number_format((float) $line->ingredient_price, 2, ',', ' ').' EUR/kg'
                                        : '-',
                                    'estimated_cost' => $line->estimated_cost !== null
                                        ? number_format((float) $line->estimated_cost, 2, ',', ' ').' EUR'
                                        : '-',
                                ])
                                ->values()
                                ->all())
                            ->table([
                                TableColumn::make('Ingrédient'),
                                TableColumn::make('À commander'),
                                TableColumn::make('Déjà commandé'),
                                TableColumn::make('Stock (indicatif)'),
                                TableColumn::make('Manque (indicatif)'),
                                TableColumn::make('Dernier prix'),
                                TableColumn::make('Coût estimé'),
                            ])
                            ->schema([
                                TextEntry::make('ingredient'),
                                TextEntry::make('to_order'),
                                TextEntry::make('ordered'),
                                TextEntry::make('stock'),
                                TextEntry::make('shortage'),
                                TextEntry::make('last_price'),
                                TextEntry::make('estimated_cost'),
                            ])
                            ->contained(false),
                    ]),
                Action::make('markWaveIngredientOrdered')
                    ->label(__('Marquer commande'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('info')
                    ->visible(fn (ProductionWave $record): bool => (int) ($record->productions_count ?? 0) > 0)
                    ->schema([
                        Select::make('ingredient_ids')
                            ->label(__('Ingrédients'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn (ProductionWave $record): array => app(WaveRequirementStatusService::class)->getIngredientOptionsForWave($record))
                            ->required(),
                    ])
                    ->action(function (ProductionWave $record, array $data): void {
                        $updatedCount = app(WaveRequirementStatusService::class)
                            ->markNotOrderedItemsAsOrderedForIngredients($record, (array) ($data['ingredient_ids'] ?? []));

                        if ($updatedCount === 0) {
                            Notification::make()
                                ->title(__('Aucun item mis à jour'))
                                ->body(__('Seuls les items "Non commandé" sont marqués automatiquement.'))
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Commande marquée'))
                            ->body(__('Items mis à jour: :count', ['count' => $updatedCount]))
                            ->success()
                            ->send();
                    }),
                Action::make('printProcurementPlan')
                    ->label('Imprimer plan')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (ProductionWave $record): string => route('production-waves.procurement-plan.print', $record))
                    ->openUrlInNewTab(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount('productions'));
    }
}
