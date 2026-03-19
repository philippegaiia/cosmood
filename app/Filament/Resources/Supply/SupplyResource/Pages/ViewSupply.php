<?php

namespace App\Filament\Resources\Supply\SupplyResource\Pages;

use App\Filament\Resources\Supply\SupplyResource;
use App\Services\Production\ProductionAllocationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewSupply extends ViewRecord
{
    protected static string $resource = SupplyResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => auth()->user()?->canManageSupplyInventory() ?? false),
            Action::make('releaseAllAllocations')
                ->label(__('Libérer toutes les réservations'))
                ->color('danger')
                ->icon('heroicon-o-lock-open')
                ->requiresConfirmation()
                ->modalHeading(__('Libérer toutes les réservations'))
                ->modalDescription(__('Cette action va libérer toutes les réservations actives pour ce lot. Les productions concernées ne seront plus approvisionnées.'))
                ->modalSubmitActionLabel(__('Oui, libérer'))
                ->visible(fn (): bool => auth()->user()?->canManageProductionPlanning() ?? false)
                ->action(function (): void {
                    if (! (auth()->user()?->canManageProductionPlanning() ?? false)) {
                        Notification::make()
                            ->title(__('Accès refusé'))
                            ->body(__('Vous n’avez pas l’autorisation de libérer les réservations de ce lot.'))
                            ->warning()
                            ->send();

                        return;
                    }

                    $supply = $this->record;
                    $allocationService = app(ProductionAllocationService::class);

                    $allocations = $supply->allocations()
                        ->where('status', 'reserved')
                        ->with('productionItem')
                        ->get();

                    $count = 0;
                    foreach ($allocations as $allocation) {
                        $allocationService->release($allocation);
                        $count++;
                    }

                    Notification::make()
                        ->title(__('Réservations libérées'))
                        ->body("{$count} réservation(s) ont été libérées.")
                        ->success()
                        ->send();
                })
                ->disabled(fn () => $this->record->allocations()->where('status', 'reserved')->count() === 0)
                ->tooltip(fn () => $this->record->allocations()->where('status', 'reserved')->count() === 0
                    ? 'Aucune réservation active'
                    : null),
        ];
    }
}
