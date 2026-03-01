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
            )
            ->columns([
                TextColumn::make('production.batch_number')
                    ->label('Production')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('production.product.name')
                    ->label('Produit')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Tâche')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('productionTaskType.name')
                    ->label('Type')
                    ->badge()
                    ->color(fn ($record) => $record->productionTaskType?->color ?? 'gray'),

                TextColumn::make('duration_minutes')
                    ->label('Durée')
                    ->formatStateUsing(fn ($state) => $state ? $state.' min' : '-')
                    ->sortable(),
            ])
            ->recordUrl(fn ($record) => $record->production_id
                ? ProductionResource::getUrl('view', ['record' => $record->production_id])
                : null)
            ->emptyStateHeading('Aucune tâche aujourd\'hui')
            ->emptyStateDescription('Toutes les tâches sont terminées ou aucune n\'est planifiée.')
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return true;
    }
}
