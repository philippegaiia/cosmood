<?php

namespace App\Filament\Resources\Production\ProductionWaves\Tables;

use App\Models\Production\ProductionWave;
use App\Services\Production\WaveProcurementService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
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
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approuver')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (ProductionWave $record): bool => $record->isDraft())
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
                    ->visible(fn (ProductionWave $record): bool => $record->isApproved())
                    ->action(function (ProductionWave $record): void {
                        $record->start();
                    }),
                Action::make('complete')
                    ->label('Terminer')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ProductionWave $record): bool => $record->isInProgress())
                    ->action(function (ProductionWave $record): void {
                        $record->complete();
                    }),
                Action::make('cancel')
                    ->label('Annuler')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ProductionWave $record): bool => ! $record->isCancelled() && ! $record->isCompleted())
                    ->action(function (ProductionWave $record): void {
                        $record->cancel();
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
                Action::make('printProcurementPlan')
                    ->label('Imprimer plan')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (ProductionWave $record): string => route('production-waves.procurement-plan.print', $record))
                    ->openUrlInNewTab(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount('productions'));
    }
}
