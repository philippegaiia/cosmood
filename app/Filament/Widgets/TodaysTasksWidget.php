<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Production\ProductionResource;
use App\Models\Production\ProductionTask;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

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
}
