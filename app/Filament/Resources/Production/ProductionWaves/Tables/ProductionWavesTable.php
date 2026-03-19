<?php

namespace App\Filament\Resources\Production\ProductionWaves\Tables;

use App\Enums\ProductionStatus;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class ProductionWavesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Nom'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Statut'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('coverage_signal')
                    ->label(__('Couverture appro'))
                    ->state(fn (ProductionWave $record): string => (string) ($record->getAttribute('coverage_signal_label') ?? $record->getCoverageSignalLabel()))
                    ->badge()
                    ->color(fn (ProductionWave $record): string => (string) ($record->getAttribute('coverage_signal_color') ?? $record->getCoverageSignalColor()))
                    ->tooltip(fn (ProductionWave $record): string => (string) ($record->getAttribute('coverage_signal_tooltip') ?? $record->getCoverageSignalTooltip())),
                TextColumn::make('fabrication_signal')
                    ->label(__('Fabrication sécurisée'))
                    ->state(fn (ProductionWave $record): string => (string) ($record->getAttribute('fabrication_signal_label') ?? $record->getFabricationSignalLabel()))
                    ->badge()
                    ->color(fn (ProductionWave $record): string => (string) ($record->getAttribute('fabrication_signal_color') ?? $record->getFabricationSignalColor()))
                    ->tooltip(fn (ProductionWave $record): string => (string) ($record->getAttribute('fabrication_signal_tooltip') ?? $record->getFabricationSignalTooltip())),
                TextColumn::make('status_sync_advisory')
                    ->label(__('Alerte flux'))
                    ->state(fn (ProductionWave $record): ?string => $record->getStatusAdvisoryMessage())
                    ->badge()
                    ->color('warning')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('productions_count')
                    ->label(__('Productions'))
                    ->badge(),
                TextColumn::make('planned_start_date')
                    ->label(__('Début prévu'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('planned_end_date')
                    ->label(__('Fin prévue'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('approvedBy.name')
                    ->label(__('Approuvé par'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approved_at')
                    ->label(__('Approuvé le'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completed_at')
                    ->label(__('Terminé le'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('Approuver'))
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
                    ->label(__('Démarrer'))
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (ProductionWave $record): bool => $record->isApproved() && (auth()->user()?->canManageWaveLifecycle() ?? false))
                    ->authorize(fn (): bool => auth()->user()?->canManageWaveLifecycle() ?? false)
                    ->action(function (ProductionWave $record): void {
                        $record->start();
                    }),
                Action::make('complete')
                    ->label(__('Terminer'))
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
                    ->label(__('Annuler'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ProductionWave $record): bool => ! $record->isCancelled() && ! $record->isCompleted() && (auth()->user()?->canManageWaveLifecycle() ?? false))
                    ->authorize(fn (): bool => auth()->user()?->canManageWaveLifecycle() ?? false)
                    ->action(function (ProductionWave $record): void {
                        $record->cancel();
                    }),
                Action::make('hardDeleteWave')
                    ->label(__('Supprimer définitivement'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ProductionWave $record): bool => ! $record->isInProgress() && ! $record->isCompleted() && (auth()->user()?->canDeleteWaves() ?? false))
                    ->authorize(fn (): bool => auth()->user()?->canDeleteWaves() ?? false)
                    ->modalDescription(__('Supprime définitivement la vague et ses productions. Les allocations doivent être désallouées et les engagements PO retirés manuellement.'))
                    ->action(function (ProductionWave $record): void {
                        try {
                            app(WaveDeletionService::class)->hardDeleteWaveWithProductions($record);

                            Notification::make()
                                ->title(__('Vague supprimée définitivement'))
                                ->success()
                                ->send();
                        } catch (\InvalidArgumentException $exception) {
                            Notification::make()
                                ->title(__('Suppression impossible'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('replanWave')
                    ->label(__('Replanifier'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->color('info')
                    ->visible(fn (ProductionWave $record): bool => ! $record->isInProgress() && ! $record->isCancelled() && ! $record->isCompleted() && (int) ($record->productions_count ?? 0) > 0 && (auth()->user()?->canManageProductionPlanning() ?? false))
                    ->authorize(fn (): bool => auth()->user()?->canManageProductionPlanning() ?? false)
                    ->schema([
                        DatePicker::make('start_date')
                            ->label(__('Nouveau départ'))
                            ->native(false)
                            ->required()
                            ->default(fn (ProductionWave $record): string => $record->planned_start_date?->toDateString() ?? now()->toDateString()),
                        TextInput::make('fallback_daily_capacity')
                            ->label(__('Capacité / jour sans ligne'))
                            ->numeric()
                            ->minValue(1)
                            ->default(4)
                            ->required(),
                        Toggle::make('skip_weekends')
                            ->label(__('Ignorer weekends'))
                            ->default(true),
                        Toggle::make('skip_holidays')
                            ->label(__('Ignorer jours fériés'))
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
                                ->title(__('Aucune production replanifiée'))
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Vague replanifiée'))
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
                    ->label(__('Plan achats'))
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->color('gray')
                    ->modalHeading(fn (ProductionWave $record): string => __('Plan achats - :name', ['name' => $record->name]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Fermer'))
                    ->schema([
                        RepeatableEntry::make('planning')
                            ->label(__('Besoins agrégés'))
                            ->columnSpanFull()
                            ->extraAttributes([
                                'class' => 'max-w-full overflow-x-auto [&>table]:min-w-[82rem] [&>table]:table-fixed',
                            ])
                            ->state(function (ProductionWave $record): array {
                                $service = app(WaveProcurementService::class);

                                return $service
                                    ->getPlanningList($record)
                                    ->map(function (object $line) use ($service): array {
                                        $displayUnit = (string) ($line->display_unit ?? 'kg');

                                        return [
                                            'ingredient' => (string) ($line->ingredient_name ?? '-'),
                                            'need_date' => $line->need_date
                                                ? Carbon::parse($line->need_date)->format('d/m/Y')
                                                : '-',
                                            'total_requirement' => $service->formatPlanningQuantity((float) ($line->total_wave_requirement ?? 0), $displayUnit),
                                            'remaining_requirement' => $service->formatPlanningQuantity((float) ($line->remaining_requirement ?? 0), $displayUnit),
                                            'available_stock' => $service->formatPlanningQuantity((float) ($line->available_stock ?? 0), $displayUnit),
                                            'wave_ordered_quantity' => $service->formatPlanningQuantity((float) ($line->wave_ordered_quantity ?? 0), $displayUnit),
                                            'wave_received_quantity' => $service->formatPlanningQuantity((float) ($line->wave_received_quantity ?? 0), $displayUnit),
                                            'open_orders_not_committed' => $service->formatPlanningQuantity((float) ($line->open_orders_not_committed ?? 0), $displayUnit),
                                            'remaining_to_order' => $service->formatPlanningQuantity((float) ($line->remaining_to_order ?? 0), $displayUnit),
                                            'last_price' => (float) $line->ingredient_price > 0
                                                ? number_format((float) $line->ingredient_price, 2, ',', ' ').' '.__('EUR/kg')
                                                : '-',
                                            'estimated_cost' => $line->estimated_cost !== null
                                                ? number_format((float) $line->estimated_cost, 2, ',', ' ').' '.__('EUR')
                                                : '-',
                                        ];
                                    })
                                    ->values()
                                    ->all();
                            })
                            ->table(self::getProcurementPlanTableColumns())
                            ->schema([
                                TextEntry::make('ingredient'),
                                TextEntry::make('need_date'),
                                TextEntry::make('total_requirement'),
                                TextEntry::make('remaining_requirement'),
                                TextEntry::make('available_stock'),
                                TextEntry::make('wave_ordered_quantity'),
                                TextEntry::make('wave_received_quantity'),
                                TextEntry::make('open_orders_not_committed'),
                                TextEntry::make('remaining_to_order'),
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
                    ->label(__('Imprimer plan'))
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (ProductionWave $record): string => route('production-waves.procurement-plan.print', $record))
                    ->openUrlInNewTab(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('productions')
                ->with(['approvedBy:id,name'])
                ->withExists([
                    'productions as has_started_productions' => fn (Builder $productionsQuery): Builder => $productionsQuery->whereIn('status', [
                        ProductionStatus::Ongoing->value,
                        ProductionStatus::Finished->value,
                    ]),
                    'productions as has_non_terminal_productions' => fn (Builder $productionsQuery): Builder => $productionsQuery->whereNotIn('status', [
                        ProductionStatus::Finished->value,
                        ProductionStatus::Cancelled->value,
                    ]),
                ]));
    }

    /**
     * @return array<int, TableColumn>
     */
    private static function getProcurementPlanTableColumns(): array
    {
        return [
            TableColumn::make(__('Ingrédient'))
                ->width('12rem')
                ->wrapHeader(),
            TableColumn::make(__('Date besoin'))
                ->width('7rem')
                ->wrapHeader(),
            TableColumn::make(__('Besoin'))
                ->width('7rem')
                ->wrapHeader(),
            TableColumn::make(__('Restant'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('Stock dispo'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('Cmd vague'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('Reçu vague'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('PO non engagées'))
                ->width('9rem')
                ->wrapHeader(),
            TableColumn::make(__('À commander'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('Dernier prix'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('Coût estimé'))
                ->width('8rem')
                ->wrapHeader(),
        ];
    }
}
