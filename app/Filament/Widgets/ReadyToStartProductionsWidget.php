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
use Illuminate\Database\Eloquent\Builder;

/**
 * Ready to Start Productions Widget.
 *
 * Shows productions that are ready to start:
 * - Status = Confirmed
 * - All supplies allocated
 * - Production date today or in the next 7 days
 *
 * Quick action to change status to Ongoing.
 */
class ReadyToStartProductionsWidget extends BaseWidget
{
    protected static ?string $heading = 'Prêts à lancer';

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
                    ->with(['product', 'productionLine'])
                    ->where('status', ProductionStatus::Confirmed)
                    ->whereHas('productionItems', function (Builder $query): void {
                        $query->whereHas('allocations');
                    })
                    ->whereDate('production_date', '>=', now())
                    ->whereDate('production_date', '<=', now()->addDays(7))
                    ->orderBy('production_date')
                    ->limit(6)
            )
            ->columns([
                TextColumn::make('production_date')
                    ->label(__('Date'))
                    ->date('d/m')
                    ->sortable(),

                TextColumn::make('batch_number')
                    ->label(__('Lot'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label(__('Produit'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('productionLine.name')
                    ->label(__('Ligne'))
                    ->placeholder(__('Sans ligne'))
                    ->sortable(),
            ])
            ->recordUrl(fn (Production $record): string => ProductionResource::getUrl('view', ['record' => $record]))
            ->actions([
                Action::make('launch')
                    ->label(__('Lancer'))
                    ->icon(Heroicon::Play)
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Production $record): void {
                        $record->update(['status' => ProductionStatus::Ongoing]);
                    }),
            ])
            ->emptyStateHeading(__('Aucune production prête'))
            ->emptyStateDescription(__('Toutes les productions confirmées ont les stocks nécessaires.'))
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return true;
    }
}
