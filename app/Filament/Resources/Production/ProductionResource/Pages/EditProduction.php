<?php

namespace App\Filament\Resources\Production\ProductionResource\Pages;

use App\Filament\Resources\Production\ProductionResource;
use App\Services\Production\MasterbatchService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduction extends EditRecord
{
    protected static string $resource = ProductionResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('Exporter PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->url(fn (): string => route('productions.sheet-pdf', $this->record))
                ->openUrlInNewTab(),
            Action::make('printSheet')
                ->label('Imprimer fiche')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('productions.print-sheet', $this->record))
                ->openUrlInNewTab(),
            Action::make('applyMasterbatchTraceability')
                ->label('Importer traçabilité MB')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->visible(fn (): bool => (bool) $this->record?->masterbatch_lot_id)
                ->requiresConfirmation()
                ->action(function (): void {
                    $masterbatchService = app(MasterbatchService::class);
                    $mismatches = $masterbatchService->getPercentageMismatches($this->record);
                    $updatedCount = $masterbatchService->applyTraceabilityToProductionItems($this->record);

                    if ($updatedCount === 0) {
                        Notification::make()
                            ->title('Aucun item mis à jour')
                            ->body('Vérifier les ingrédients/phase ET que la traçabilité du masterbatch contient bien des lots supply.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Traçabilité masterbatch importée')
                        ->body($updatedCount.' item(s) de production mis à jour.')
                        ->success()
                        ->send();

                    if ($mismatches->isNotEmpty()) {
                        $preview = $mismatches
                            ->take(3)
                            ->map(fn (array $line): string => ($line['ingredient_name'] ?? 'Ingrédient').': '.$line['production_percentage'].'% vs MB '.$line['masterbatch_percentage'].'%')
                            ->implode(' | ');

                        $suffix = $mismatches->count() > 3 ? ' (+'.($mismatches->count() - 3).' autres)' : '';

                        Notification::make()
                            ->title('Alerte cohérence pourcentages')
                            ->body('Des écarts de % ont été détectés: '.$preview.$suffix)
                            ->warning()
                            ->send();
                    }
                }),
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
