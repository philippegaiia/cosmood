<?php

namespace App\Filament\Resources\Production\ProductionResource\Pages;

use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Filament\Resources\Production\ProductionResource;
use App\Models\Production\Formula;
use App\Services\Production\MasterbatchService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
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
        if ($this->isTransitioningToOngoing()) {
            if ($notificationData = $this->getOngoingBlockerNotificationData()) {
                Notification::make()
                    ->warning()
                    ->title($notificationData['title'])
                    ->body($notificationData['body'])
                    ->send();

                throw new Halt;
            }
        }

        if ($this->isTransitioningToFinished()) {
            if ($notificationData = $this->getFinishBlockerNotificationData()) {
                Notification::make()
                    ->danger()
                    ->title($notificationData['title'])
                    ->body($notificationData['body'])
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

        if (! $this->shouldBlockSaponifiedTotalMismatch()) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('Total saponifié invalide'))
            ->body(__('Le total saponifié est à :total %. Il doit être égal à 100 % quand au moins une phase saponifiée est présente.', [
                'total' => number_format($this->getSaponifiedTotalFromState(), 2, '.', ' '),
            ]))
            ->send();

        throw new Halt;
    }

    private function getStatusTransitionConfirmationSessionKey(): string
    {
        return sprintf('production:status-confirm:%s', $this->record->getKey());
    }

    private function hasStatusTransitionConfirmation(string $signature): bool
    {
        return Session::get($this->getStatusTransitionConfirmationSessionKey()) === $signature;
    }

    private function storeStatusTransitionConfirmation(string $signature): void
    {
        Session::put($this->getStatusTransitionConfirmationSessionKey(), $signature);
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

    private function isTransitioningToOngoing(): bool
    {
        $currentStatus = $this->record->status instanceof ProductionStatus
            ? $this->record->status
            : ProductionStatus::tryFrom((string) ($this->record->status ?? ''));

        $nextStatus = ProductionStatus::tryFrom((string) ($this->data['status'] ?? ''));

        if (! $nextStatus) {
            return false;
        }

        return $nextStatus === ProductionStatus::Ongoing && $currentStatus !== ProductionStatus::Ongoing;
    }

    /**
     * @return array{title: string, body: string}|null
     */
    private function getOngoingBlockerNotificationData(): ?array
    {
        $unallocatedIngredientNames = $this->record->getUnallocatedIngredientNamesForOngoing();

        if ($unallocatedIngredientNames === []) {
            return null;
        }

        return [
            'title' => __('Allocations incomplètes'),
            'body' => __('Impossible de passer en cours : affecter les lots pour :items.', [
                'items' => implode(', ', $unallocatedIngredientNames),
            ]),
        ];
    }

    /**
     * @return array{title: string, body: string}|null
     */
    private function getFinishBlockerNotificationData(): ?array
    {
        $missingIngredientNames = $this->record->getMissingLotIngredientNamesForFinish();

        if ($missingIngredientNames !== []) {
            return [
                'title' => __('Lots supply manquants'),
                'body' => __('Impossible de terminer : sélectionner un lot pour :items.', [
                    'items' => implode(', ', $missingIngredientNames),
                ]),
            ];
        }

        $unfinishedTaskNames = $this->record->getIncompleteTaskNamesForFinish();

        if ($unfinishedTaskNames !== []) {
            return [
                'title' => __('Tâches incomplètes'),
                'body' => __('Impossible de terminer : finaliser :items.', [
                    'items' => implode(', ', $unfinishedTaskNames),
                ]),
            ];
        }

        $pendingQcLabels = $this->record->getIncompleteRequiredQcLabelsForFinish();

        if ($pendingQcLabels !== []) {
            return [
                'title' => __('Contrôles QC incomplets'),
                'body' => __('Impossible de terminer : renseigner les contrôles :items.', [
                    'items' => implode(', ', $pendingQcLabels),
                ]),
            ];
        }

        if ($outputBlocker = $this->record->getOutputBlockerMessageForFinish()) {
            return [
                'title' => __('Sorties à compléter'),
                'body' => __('Impossible de terminer : :reason.', [
                    'reason' => $outputBlocker,
                ]),
            ];
        }

        return null;
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
                ->label(__('Supprimer définitivement'))
                ->modalDescription(__('Supprime définitivement cette production avant démarrage.'))
                ->disabled(fn (): bool => ! $this->record->canBeDeleted())
                ->tooltip(fn (): ?string => $this->record->getDeletionBlockerMessage()),
        ];
    }

    private function shouldBlockSaponifiedTotalMismatch(): bool
    {
        if (! $this->isSoapProductionType()) {
            return false;
        }

        if ($this->getSaponifiedItemCountFromState() === 0) {
            return false;
        }

        return abs($this->getSaponifiedTotalFromState() - 100.0) >= 0.01;
    }

    private function getSaponifiedItemCountFromState(): int
    {
        $count = 0;

        foreach ($this->getSaponifiedItemsForValidation() as $item) {
            if (($item['phase'] ?? null) === Phases::Saponification->value) {
                $count++;
            }
        }

        return $count;
    }

    private function isSoapProductionType(): bool
    {
        $formulaId = (int) ($this->data['formula_id'] ?? $this->record->formula_id ?? 0);

        return $formulaId > 0 && (bool) (Formula::query()->find($formulaId)?->is_soap ?? false);
    }

    private function getSaponifiedTotalFromState(): float
    {
        $total = 0.0;

        foreach ($this->getSaponifiedItemsForValidation() as $item) {
            if (($item['phase'] ?? null) !== Phases::Saponification->value) {
                continue;
            }

            $total += (float) ($item['percentage_of_oils'] ?? 0);
        }

        return $total;
    }

    /**
     * @return array<int, array{phase: string|null, percentage_of_oils: mixed}>
     */
    private function getSaponifiedItemsForValidation(): array
    {
        $stateItems = $this->data['productionItems'] ?? null;

        if (is_array($stateItems) && $stateItems !== []) {
            return array_map(
                fn (array $item): array => [
                    'phase' => isset($item['phase']) ? (string) $item['phase'] : null,
                    'percentage_of_oils' => $item['percentage_of_oils'] ?? 0,
                ],
                $stateItems,
            );
        }

        return $this->record
            ->productionItems()
            ->get(['phase', 'percentage_of_oils'])
            ->map(fn ($item): array => [
                'phase' => isset($item->phase) ? (string) $item->phase : null,
                'percentage_of_oils' => $item->percentage_of_oils,
            ])
            ->all();
    }
}
