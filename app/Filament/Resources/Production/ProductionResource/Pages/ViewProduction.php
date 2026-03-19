<?php

namespace App\Filament\Resources\Production\ProductionResource\Pages;

use App\Filament\Resources\Production\ProductionResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ViewProduction extends ViewRecord
{
    protected static string $resource = ProductionResource::class;

    public function getTitle(): string
    {
        $permanentBatch = trim((string) ($this->record->permanent_batch_number ?? ''));
        $planningBatch = trim((string) ($this->record->batch_number ?? ''));
        $productName = trim((string) ($this->record->product?->name ?? 'Produit inconnu'));

        $lotLabel = filled($permanentBatch)
            ? $permanentBatch.' ('.$planningBatch.')'
            : ($planningBatch !== '' ? '- ('.$planningBatch.')' : '- (-)');

        return 'Production - '.$lotLabel.' - '.$productName;
    }

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
        $actions = [
            Action::make('exportPdf')
                ->label(__('Exporter PDF'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (): string => route('productions.sheet-pdf', $this->record))
                ->openUrlInNewTab(),
            Action::make('printSheet')
                ->label(__('Fiche production'))
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (): string => route('productions.print-sheet', $this->record))
                ->openUrlInNewTab(),
            Action::make('followSheet')
                ->label(__('Fiche suivi'))
                ->icon(Heroicon::OutlinedDocumentText)
                ->url(fn (): string => route('productions.follow-sheet', $this->record))
                ->openUrlInNewTab(),
        ];

        if (auth()->user()?->can('Update:Production') ?? false) {
            $actions[] = EditAction::make();
        }

        return $actions;
    }
}
