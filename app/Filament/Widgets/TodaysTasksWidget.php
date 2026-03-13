<?php

namespace App\Filament\Widgets;

use App\Enums\ProductionStatus;
use App\Filament\Resources\Production\ProductionResource;
use App\Models\Production\ProductionTask;
use App\Services\Production\TaskGenerationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Today's Tasks Widget.
 *
 * Shows production tasks scheduled for today.
 * Compact view with minimal information.
 * Full width layout.
 */
class TodaysTasksWidget extends BaseWidget
{
    protected static ?string $heading = 'Tâches du jour';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProductionTask::query()
                    ->with(['production.product', 'productionTaskType'])
                    ->whereDate('scheduled_date', today())
                    ->where('is_finished', false)
                    ->whereNull('cancelled_at')
                    ->orderBy('sequence_order')
                    ->limit(8)
            )
            ->columns([
                TextColumn::make('production.batch_number')
                    ->label(__('Lot'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('production.product.name')
                    ->label(__('Produit'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('Tâche'))
                    ->searchable()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('finish')
                    ->label(__('Terminer'))
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (ProductionTask $record): bool => $this->canFinishTask($record))
                    ->action(function (ProductionTask $record): void {
                        if (! $this->canExecuteTaskFromDashboard($record)) {
                            Notification::make()
                                ->warning()
                                ->title(__('Exécution non autorisée'))
                                ->body(__('Les tâches ne peuvent être terminées ici que sur une production en cours.'))
                                ->send();

                            return;
                        }

                        try {
                            app(TaskGenerationService::class)->markTaskAsFinished($record);
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->danger()
                                ->title($exception->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->recordUrl(fn ($record) => $record->production_id
                ? ProductionResource::getUrl('view', ['record' => $record->production_id])
                : null)
            ->emptyStateHeading(__('Aucune tâche aujourd\'hui'))
            ->emptyStateDescription(__('Toutes les tâches sont terminées ou aucune n\'est planifiée.'))
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return true;
    }

    private function canFinishTask(ProductionTask $task): bool
    {
        return $this->canExecuteTaskFromDashboard($task)
            && ! app(TaskGenerationService::class)->isBlockedByDependencies($task);
    }

    private function canExecuteTaskFromDashboard(ProductionTask $task): bool
    {
        return ! $task->is_finished
            && ! $task->isCancelled()
            && $task->production?->status === ProductionStatus::Ongoing
            && $task->scheduled_date !== null
            && ! Carbon::parse($task->scheduled_date)->isFuture()
            && (auth()->user()?->canStartProductionRuns() ?? false);
    }
}
