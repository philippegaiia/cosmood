<?php

namespace App\Filament\Resources\Production\ProductionWaves\Pages;

use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListProductionWaves extends ListRecords
{
    protected static string $resource = ProductionWaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('coverageLegend')
                ->label(__('Légende couverture'))
                ->icon(Heroicon::OutlinedInformationCircle)
                ->color('gray')
                ->modalHeading(__('Légende couverture appro'))
                ->modalDescription(__('Vert: prêt (pas de manque). Orange: partiel (stock/PO/provisoire à finaliser). Rouge: à sécuriser (manque indicatif).'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Fermer')),
            CreateAction::make(),
        ];
    }
}
