<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Production\ProductionResource;
use App\Models\Production\ProductionTask;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Today's Productions Widget - Task View.
 *
 * Shows production tasks scheduled for today (first task of each production).
 * Displays batch numbers, product, and quantities.
 */
class TodaysProductionsWidget extends BaseWidget
{
    protected static ?string $heading = 'Planification du jour';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 6,
        'lg' => 6,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProductionTask::query()
                    ->with(['production.product'])
                    ->where('name', 'Production') // First task of each production
                    ->whereDate('scheduled_date', today())
                    ->whereNull('cancelled_at')
                    ->orderBy('sequence_order')
            )
            ->columns([
                TextColumn::make('production.lot_display')
                    ->label(__('N° Lot'))
                    ->state(fn ($record) => $record->production?->getLotDisplayLabel())
                    ->searchable(query: fn ($query, $search) => $query
                        ->whereHas('production', fn ($q) => $q
                            ->where('batch_number', 'like', "%{$search}%")
                            ->orWhere('permanent_batch_number', 'like', "%{$search}%")))
                    ->sortable(query: fn ($query, $direction) => $query
                        ->join('productions', 'production_tasks.production_id', '=', 'productions.id')
                        ->orderBy('productions.permanent_batch_number', $direction)
                        ->orderBy('productions.batch_number', $direction)),

                TextColumn::make('production.product.name')
                    ->label(__('Produit'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('production.expected_units')
                    ->label(__('Qté (unités)'))
                    ->numeric()
                    ->placeholder(__('-'))
                    ->sortable(),

                TextColumn::make('production.planned_quantity')
                    ->label(__('Qté attendue (kg)'))
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' kg')
                    ->placeholder(__('-'))
                    ->sortable(),

                TextColumn::make('production.status')
                    ->label(__('Statut'))
                    ->badge()
                    ->sortable(),
            ])
            ->recordUrl(fn ($record) => $record->production_id
                ? ProductionResource::getUrl('view', ['record' => $record->production_id])
                : null)
            ->emptyStateHeading(__('Aucune production aujourd\'hui'))
            ->emptyStateDescription(__('Aucune tâche de production n\'est planifiée pour aujourd\'hui.'))
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return true;
    }
}
