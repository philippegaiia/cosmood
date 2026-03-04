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
            EditAction::make(),
            Action::make('releaseAllAllocations')
                ->label('Libérer toutes les réservations')
                ->color('danger')
                ->icon('heroicon-o-lock-open')
                ->requiresConfirmation()
                ->modalHeading('Libérer toutes les réservations')
                ->modalDescription('Cette action va libérer toutes les réservations actives pour ce lot. Les productions concernées ne seront plus approvisionnées.')
                ->modalSubmitActionLabel('Oui, libérer')
                ->visible(fn () => auth()->check())
                ->action(function (): void {
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
                        ->title('Réservations libérées')
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
