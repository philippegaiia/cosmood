<?php

namespace App\Filament\Resources\Production\ProductionResource\Pages;

use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Filament\Resources\Production\ProductionResource;
use App\Models\Production\Formula;
use App\Services\Production\MasterbatchService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Session;

class EditProduction extends EditRecord
{
    protected static string $resource = ProductionResource::class;

    public static bool $formActionsAreSticky = true;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function getTitle(): string
    {
        $permanentBatch = trim((string) ($this->record->permanent_batch_number ?? ''));
        $planningBatch = trim((string) ($this->record->batch_number ?? ''));
        $productName = trim((string) ($this->record->product?->name ?? 'Produit inconnu'));

        $lotLabel = filled($permanentBatch)
            ? $permanentBatch.' ('.$planningBatch.')'
            : ($planningBatch !== '' ? '- ('.$planningBatch.')' : '- (-)');

        return 'Modifier Production - '.$lotLabel.' - '.$productName;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['product_id'] = $this->record->product_id;

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->refresh();

        $this->refreshFormData([
            'permanent_batch_number',
        ]);
    }

    protected function beforeSave(): void
    {
        if ($this->isTransitioningToFinished()) {
            $missingIngredientNames = $this->record->getMissingLotIngredientNamesForFinish();

            if ($missingIngredientNames !== []) {
                Notification::make()
                    ->danger()
                    ->title('Lots supply manquants')
                    ->body('Impossible de terminer: sélectionner un lot pour '.implode(', ', $missingIngredientNames).'.')
                    ->send();

                throw new Halt;
            }
        }

        if ($this->shouldConfirmStatusTransition()) {
            $statusSignature = $this->getStatusTransitionConfirmationSignature();

            if (! $this->hasStatusTransitionConfirmation($statusSignature)) {
                $this->storeStatusTransitionConfirmation($statusSignature);

                Notification::make()
                    ->warning()
                    ->title('Confirmer le changement de statut')
                    ->body($this->getStatusTransitionConfirmationMessage())
                    ->send();

                throw new Halt;
            }

            $this->clearStatusTransitionConfirmationState();
        } else {
            $this->clearStatusTransitionConfirmationState();
        }

        if (! $this->shouldConfirmSaponifiedTotalMismatch()) {
            $this->clearSaponifiedConfirmationState();

            return;
        }

        $signature = $this->getSaponifiedConfirmationSignature();

        if ($this->hasSaponifiedConfirmation($signature)) {
            $this->clearSaponifiedConfirmationState();

            return;
        }

        $this->storeSaponifiedConfirmation($signature);

        Notification::make()
            ->warning()
            ->title('Total saponifie different de 100%')
            ->body('Le total saponifie est a '.number_format($this->getSaponifiedTotalFromState(), 2, '.', ' ').' %. Cliquez encore sur Enregistrer pour confirmer.')
            ->send();

        throw new Halt;
    }

    private function getSaponifiedConfirmationSessionKey(): string
    {
        return sprintf('production:saponified-confirm:%s', $this->record->getKey());
    }

    private function getStatusTransitionConfirmationSessionKey(): string
    {
        return sprintf('production:status-confirm:%s', $this->record->getKey());
    }

    private function getSaponifiedConfirmationSignature(): string
    {
        return implode('|', [
            (string) ($this->record->getKey() ?? 'new'),
            (string) ((int) ($this->record->formula_id ?? 0)),
            number_format($this->getSaponifiedTotalFromState(), 4, '.', ''),
        ]);
    }

    private function hasSaponifiedConfirmation(string $signature): bool
    {
        return Session::get($this->getSaponifiedConfirmationSessionKey()) === $signature;
    }

    private function hasStatusTransitionConfirmation(string $signature): bool
    {
        return Session::get($this->getStatusTransitionConfirmationSessionKey()) === $signature;
    }

    private function storeSaponifiedConfirmation(string $signature): void
    {
        Session::put($this->getSaponifiedConfirmationSessionKey(), $signature);
    }

    private function storeStatusTransitionConfirmation(string $signature): void
    {
        Session::put($this->getStatusTransitionConfirmationSessionKey(), $signature);
    }

    private function clearSaponifiedConfirmationState(): void
    {
        Session::forget($this->getSaponifiedConfirmationSessionKey());
    }

    private function clearStatusTransitionConfirmationState(): void
    {
        Session::forget($this->getStatusTransitionConfirmationSessionKey());
    }

    private function shouldConfirmStatusTransition(): bool
    {
        $currentStatus = $this->record->status instanceof ProductionStatus
            ? $this->record->status
            : ProductionStatus::tryFrom((string) ($this->record->status ?? ''));

        $nextStatus = ProductionStatus::tryFrom((string) ($this->data['status'] ?? ''));

        if (! $currentStatus || ! $nextStatus) {
            return false;
        }

        return $currentStatus !== $nextStatus;
    }

    private function isTransitioningToFinished(): bool
    {
        $currentStatus = $this->record->status instanceof ProductionStatus
            ? $this->record->status
            : ProductionStatus::tryFrom((string) ($this->record->status ?? ''));

        $nextStatus = ProductionStatus::tryFrom((string) ($this->data['status'] ?? ''));

        if (! $nextStatus) {
            return false;
        }

        return $nextStatus === ProductionStatus::Finished && $currentStatus !== ProductionStatus::Finished;
    }

    private function getStatusTransitionConfirmationSignature(): string
    {
        $currentStatus = $this->record->status instanceof ProductionStatus
            ? $this->record->status
            : ProductionStatus::tryFrom((string) ($this->record->status ?? ''));

        $nextStatus = ProductionStatus::tryFrom((string) ($this->data['status'] ?? ''));

        return implode('|', [
            (string) ($this->record->getKey() ?? 'new'),
            (string) ($currentStatus?->value ?? ''),
            (string) ($nextStatus?->value ?? ''),
        ]);
    }

    private function getStatusTransitionConfirmationMessage(): string
    {
        $currentStatus = $this->record->status instanceof ProductionStatus
            ? $this->record->status
            : ProductionStatus::tryFrom((string) ($this->record->status ?? ''));

        $nextStatus = ProductionStatus::tryFrom((string) ($this->data['status'] ?? ''));

        return sprintf(
            'Vous allez passer de "%s" à "%s". Cliquez encore sur Enregistrer pour confirmer.',
            $currentStatus?->getLabel() ?? '-',
            $nextStatus?->getLabel() ?? '-',
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('PDF production')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (): string => route('productions.sheet-pdf', $this->record))
                ->openUrlInNewTab(),
            Action::make('printSheet')
                ->label('Fiche de production')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (): string => route('productions.print-sheet', $this->record))
                ->openUrlInNewTab(),
            Action::make('followSheet')
                ->label('Fiche suivi')
                ->icon(Heroicon::OutlinedDocumentText)
                ->url(fn (): string => route('productions.follow-sheet', $this->record))
                ->openUrlInNewTab(),
            Action::make('applyMasterbatchTraceability')
                ->label('Importer traçabilité MB')
                ->icon(Heroicon::OutlinedArrowDownTray)
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
            DeleteAction::make()
                ->visible(fn (): bool => $this->record->status !== ProductionStatus::Finished),
            ForceDeleteAction::make()
                ->visible(fn (): bool => $this->record->status !== ProductionStatus::Finished),
            RestoreAction::make(),
        ];
    }

    private function shouldConfirmSaponifiedTotalMismatch(): bool
    {
        if (! $this->isSoapProductionType()) {
            return false;
        }

        return abs($this->getSaponifiedTotalFromState() - 100.0) >= 0.01;
    }

    private function isSoapProductionType(): bool
    {
        $formulaId = (int) ($this->data['formula_id'] ?? $this->record->formula_id ?? 0);

        return $formulaId > 0 && (bool) (Formula::query()->find($formulaId)?->is_soap ?? false);
    }

    private function getSaponifiedTotalFromState(): float
    {
        $total = 0.0;

        foreach (($this->data['productionItems'] ?? []) as $item) {
            if (($item['phase'] ?? null) !== Phases::Saponification->value) {
                continue;
            }

            $total += (float) ($item['percentage_of_oils'] ?? 0);
        }

        return $total;
    }
}
