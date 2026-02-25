<?php

namespace App\Filament\Resources\Production\ProductionResource\Pages;

use App\Filament\Resources\Production\ProductionResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewProduction extends ViewRecord
{
    protected static string $resource = ProductionResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('Exporter PDF production')
                ->icon('heroicon-o-document-arrow-down')
                ->url(fn (): string => route('productions.sheet-pdf', $this->record))
                ->openUrlInNewTab(),
            Action::make('printSheet')
                ->label('Imprimer fiche de production')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('productions.print-sheet', $this->record))
                ->openUrlInNewTab(),
            Action::make('followSheet')
                ->label('Fiche de suivi')
                ->icon('heroicon-o-document-text')
                ->url(fn (): string => route('productions.follow-sheet', $this->record))
                ->openUrlInNewTab(),
            EditAction::make(),
        ];
    }
}
