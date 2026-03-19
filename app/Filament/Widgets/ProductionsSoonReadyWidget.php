<?php

namespace App\Filament\Widgets;

use App\Enums\ProductionStatus;
use App\Filament\Resources\Production\ProductionResource;
use App\Models\Production\Production;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use InvalidArgumentException;

/**
 * Productions Soon Ready Widget.
 *
 * Shows productions that will be ready soon (finished):
 * - Status = Ongoing (currently in production)
 * - Will be finished in the next 1-3 days
 *
 * Quick action to mark as finished.
 */
class ProductionsSoonReadyWidget extends BaseWidget
{
    protected static ?string $heading = 'Productions bientôt terminées';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 6,
        'lg' => 6,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Production::query()
                    ->with(['product', 'wave'])
                    ->where('status', ProductionStatus::Ongoing)
                    ->orderBy('production_date')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('batch_number')
                    ->label(__('Lot'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label(__('Produit'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('production_date')
                    ->label(__('Date début'))
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('planned_quantity')
                    ->label(__('Qté planifiée'))
                    ->numeric()
                    ->suffix(' kg')
                    ->sortable(),

                TextColumn::make('actual_units')
                    ->label(__('Unités produites'))
                    ->numeric()
                    ->placeholder(__('-'))
                    ->sortable(),
            ])
            ->actions([
                Action::make('finish')
                    ->label(__('Terminer'))
                    ->icon(Heroicon::Check)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => auth()->user()?->canFinishProductionRuns() ?? false)
                    ->authorize(fn (): bool => auth()->user()?->canFinishProductionRuns() ?? false)
                    ->action(function (Production $record): void {
                        $record->refresh();

                        if ($notificationData = $this->getFinishBlockerNotificationData($record)) {
                            Notification::make()
                                ->danger()
                                ->title($notificationData['title'])
                                ->body($notificationData['body'])
                                ->send();

                            return;
                        }

                        try {
                            $record->update(['status' => ProductionStatus::Finished]);
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->warning()
                                ->title(__('Finalisation impossible'))
                                ->body($exception->getMessage())
                                ->send();
                        }
                    }),

                Action::make('view')
                    ->label(__('Voir'))
                    ->icon(Heroicon::Eye)
                    ->color('gray')
                    ->url(fn (Production $record): string => ProductionResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading(__('Aucune production en cours'))
            ->emptyStateDescription(__('Aucune production n\'est actuellement en cours.'))
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return true;
    }

    /**
     * @return array{title: string, body: string}|null
     */
    private function getFinishBlockerNotificationData(Production $production): ?array
    {
        $missingIngredientNames = $production->getMissingLotIngredientNamesForFinish();

        if ($missingIngredientNames !== []) {
            return [
                'title' => __('Lots supply manquants'),
                'body' => __('Impossible de terminer : sélectionner un lot pour :items.', [
                    'items' => implode(', ', $missingIngredientNames),
                ]),
            ];
        }

        $unfinishedTaskNames = $production->getIncompleteTaskNamesForFinish();

        if ($unfinishedTaskNames !== []) {
            return [
                'title' => __('Tâches incomplètes'),
                'body' => __('Impossible de terminer : finaliser :items.', [
                    'items' => implode(', ', $unfinishedTaskNames),
                ]),
            ];
        }

        $pendingQcLabels = $production->getIncompleteRequiredQcLabelsForFinish();

        if ($pendingQcLabels !== []) {
            return [
                'title' => __('Contrôles QC incomplets'),
                'body' => __('Impossible de terminer : renseigner les contrôles :items.', [
                    'items' => implode(', ', $pendingQcLabels),
                ]),
            ];
        }

        if ($outputBlocker = $production->getOutputBlockerMessageForFinish()) {
            return [
                'title' => __('Sorties à compléter'),
                'body' => __('Impossible de terminer : :reason.', [
                    'reason' => $outputBlocker,
                ]),
            ];
        }

        return null;
    }
}
