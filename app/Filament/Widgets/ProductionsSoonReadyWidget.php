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
                    ->label('Lot')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label('Produit')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('production_date')
                    ->label('Date début')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('planned_quantity')
                    ->label('Qté planifiée')
                    ->numeric()
                    ->suffix(' kg')
                    ->sortable(),

                TextColumn::make('actual_units')
                    ->label('Unités produites')
                    ->numeric()
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->actions([
                Action::make('finish')
                    ->label('Terminer')
                    ->icon(Heroicon::Check)
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Production $record): void {
                        $record->update(['status' => ProductionStatus::Finished]);
                    }),

                Action::make('view')
                    ->label('Voir')
                    ->icon(Heroicon::Eye)
                    ->color('gray')
                    ->url(fn (Production $record): string => ProductionResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('Aucune production en cours')
            ->emptyStateDescription('Aucune production n\'est actuellement en cours.')
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return true;
    }
}
