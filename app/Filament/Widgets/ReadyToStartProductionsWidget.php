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
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Ready to Start Productions Widget.
 *
 * Shows productions that are ready to start:
 * - Status = Confirmed
 * - Fabrication inputs allocated
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
                    ->visible(fn (): bool => auth()->user()?->canStartProductionRuns() ?? false)
                    ->authorize(fn (): bool => auth()->user()?->canStartProductionRuns() ?? false)
                    ->action(function (Production $record): void {
                        $record->refresh();

                        $unallocatedIngredientNames = $record->getUnallocatedIngredientNamesForOngoing();
                        $unallocatedPackagingIngredientNames = $record->getUnallocatedPackagingIngredientNamesForOngoing();

                        if ($unallocatedIngredientNames !== []) {
                            Notification::make()
                                ->warning()
                                ->title(__('Allocations incomplètes'))
                                ->body(__('Impossible de passer en cours : affecter les lots pour :items.', [
                                    'items' => implode(', ', $unallocatedIngredientNames),
                                ]))
                                ->send();

                            return;
                        }

                        try {
                            $record->update(['status' => ProductionStatus::Ongoing]);

                            if ($unallocatedPackagingIngredientNames !== []) {
                                Notification::make()
                                    ->warning()
                                    ->title(__('Packaging à suivre'))
                                    ->body(__('La fabrication démarre avec du packaging non alloué : :items. Vérifier avant le conditionnement.', [
                                        'items' => implode(', ', $unallocatedPackagingIngredientNames),
                                    ]))
                                    ->send();
                            }
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->warning()
                                ->title(__('Passage en cours impossible'))
                                ->body($exception->getMessage())
                                ->send();
                        }
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
