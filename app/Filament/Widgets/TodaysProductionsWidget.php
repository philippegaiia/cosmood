<?php

namespace App\Filament\Widgets;

use App\Enums\ProductionStatus;
use App\Filament\Resources\Production\ProductionResource;
use App\Models\Production\Production;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Today's Productions Widget.
 *
 * Shows all productions scheduled for today regardless of status.
 * Color-coded by status for quick visual identification.
 *
 * Quick action to view production details.
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
                Production::query()
                    ->with(['product', 'wave'])
                    ->whereDate('production_date', today())
                    ->orderBy('created_at')
            )
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

                TextColumn::make('planned_quantity')
                    ->label('Qté planifiée')
                    ->numeric()
                    ->suffix(' kg')
                    ->sortable(),

                TextColumn::make('wave.name')
                    ->label('Vague')
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->actions([
                Action::make('view')
                    ->label('Voir')
                    ->icon(Heroicon::Eye)
                    ->color('gray')
                    ->url(fn (Production $record): string => ProductionResource::getUrl('view', ['record' => $record])),

                Action::make('edit')
                    ->label('Modifier')
                    ->icon(Heroicon::Pencil)
                    ->color('primary')
                    ->url(fn (Production $record): string => ProductionResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Production $record): bool => $record->status !== ProductionStatus::Finished),
            ])
            ->emptyStateHeading('Aucune production aujourd\'hui')
            ->emptyStateDescription('Aucune production n\'est planifiée pour aujourd\'hui.')
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return true;
    }
}
