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
                    ->with(['product', 'wave'])
                    ->where('status', ProductionStatus::Confirmed)
                    ->whereHas('productionItems', function (Builder $query): void {
                        $query->whereHas('allocations');
                    })
                    ->whereDate('production_date', '>=', now())
                    ->whereDate('production_date', '<=', now()->addDays(7))
                    ->orderBy('production_date')
            )
            ->columns([
                TextColumn::make('production_date')
                    ->label('Date')
                    ->date('d/m')
                    ->sortable(),

                TextColumn::make('batch_number')
                    ->label('Lot')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label('Produit')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('wave.name')
                    ->label('Vague')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('planned_quantity')
                    ->label('Qté')
                    ->numeric()
                    ->suffix(' kg')
                    ->sortable(),
            ])
            ->actions([
                Action::make('launch')
                    ->label('Lancer')
                    ->icon(Heroicon::Play)
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Production $record): void {
                        $record->update(['status' => ProductionStatus::Ongoing]);
                    }),

                Action::make('view')
                    ->label('Voir')
                    ->icon(Heroicon::Eye)
                    ->color('gray')
                    ->url(fn (Production $record): string => ProductionResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('Aucune production prête')
            ->emptyStateDescription('Toutes les productions confirmées ont les stocks nécessaires.')
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return true;
    }
}
