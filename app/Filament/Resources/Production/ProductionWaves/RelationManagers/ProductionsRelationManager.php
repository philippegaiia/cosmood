<?php

namespace App\Filament\Resources\Production\ProductionWaves\RelationManagers;

use App\Enums\ProductionStatus;
use App\Filament\Resources\Production\ProductionResource;
use App\Models\Production\Production;
use App\Services\Production\ProductionStatusTransitionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductionsRelationManager extends RelationManager
{
    protected static string $relationship = 'productions';

    protected static ?string $title = 'Productions liées';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('batch_number')
                    ->label('Lot')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Produit')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('procurement_signal')
                    ->label(__('Signal appro'))
                    ->state(fn (Production $record): string => $record->getSupplyCoverageLabel())
                    ->badge()
                    ->color(fn (Production $record): string => $record->getSupplyCoverageColor()),
                TextColumn::make('manual_order_mark')
                    ->label(__('Commande passée'))
                    ->state(fn (Production $record): string => $record->hasManualOrderMarkedItems()
                        ? __('Oui (:count)', ['count' => $record->getManualOrderMarkedItemsCount()])
                        : __('Non'))
                    ->badge()
                    ->color(fn (Production $record): string => $record->hasManualOrderMarkedItems() ? 'info' : 'gray'),
                TextColumn::make('planned_quantity')
                    ->label('Qté planifiée (kg)')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                TextColumn::make('production_date')
                    ->label('Date production')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('productionLine.name')
                    ->label('Ligne')
                    ->badge()
                    ->placeholder('Non affectée')
                    ->sortable(),
                TextColumn::make('ready_date')
                    ->label('Date prêt')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('confirmProduction')
                    ->label(__('Confirmer'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Production $record): bool => $record->status === ProductionStatus::Planned)
                    ->action(function (Production $record): void {
                        $summary = app(ProductionStatusTransitionService::class)
                            ->confirmPlannedProductions(collect([$record]));

                        self::sendConfirmationNotification($summary);
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('confirmSelected')
                    ->label(__('Confirmer sélection'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $summary = app(ProductionStatusTransitionService::class)
                            ->confirmPlannedProductions($records);

                        self::sendConfirmationNotification($summary);
                    }),
            ])
            ->recordUrl(fn (Production $record): string => ProductionResource::getUrl('edit', ['record' => $record]))
            ->openRecordUrlInNewTab()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product', 'productionLine', 'productionItems.allocations']))
            ->defaultSort('production_date', 'asc');
    }

    /**
     * @param  array{confirmed: int, skipped: int, failed: int}  $summary
     */
    private static function sendConfirmationNotification(array $summary): void
    {
        $confirmed = (int) ($summary['confirmed'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);

        $notification = Notification::make()
            ->title(__('Confirmation productions'))
            ->body(__('Confirmées: :confirmed | Ignorées: :skipped | Erreurs: :failed', [
                'confirmed' => $confirmed,
                'skipped' => $skipped,
                'failed' => $failed,
            ]));

        if ($confirmed > 0 && $failed === 0) {
            $notification->success()->send();

            return;
        }

        if ($failed > 0) {
            $notification->danger()->send();

            return;
        }

        $notification->warning()->send();
    }
}
