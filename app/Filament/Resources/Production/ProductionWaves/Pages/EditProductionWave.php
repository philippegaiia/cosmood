<?php

namespace App\Filament\Resources\Production\ProductionWaves\Pages;

use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use App\Filament\Traits\UsesOptimisticLocking;
use App\Filament\Traits\UsesPresenceLock;
use App\Models\Production\ProductionWaveStockDecision;
use App\Models\Supply\Ingredient;
use App\Services\Production\ProductionAllocationService;
use App\Services\Production\WaveDeletionService;
use App\Services\Production\WaveProcurementService;
use App\Services\Production\WaveProductionPlanningService;
use App\Services\Production\WaveRequirementStatusService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

class EditProductionWave extends EditRecord
{
    use UsesOptimisticLocking;
    use UsesPresenceLock;

    protected static string $resource = ProductionWaveResource::class;

    protected string $view = 'filament.pages.edit-record-with-optimistic-locking';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $advisoryMessage = $this->record->getStatusAdvisoryMessage();

        if (! $advisoryMessage) {
            return;
        }

        Notification::make()
            ->title(__('Synchronisation suggérée'))
            ->body($advisoryMessage)
            ->warning()
            ->send();
    }

    protected function afterFill(): void
    {
        $this->initializeOptimisticLocking();
        $this->initializePresenceLocking();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->ensurePresenceLockOwnership();
        $this->assertNoConcurrentModification();
        $this->incrementLockVersion($data);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->handleRecordUpdateWithOptimisticLock($record, $data);
    }

    protected function afterSave(): void
    {
        $plannedStartDateChanged = $this->record->wasChanged('planned_start_date');

        $this->refreshLockVersionAfterSave();

        if (! $plannedStartDateChanged) {
            return;
        }

        $this->record->refresh();

        if (! $this->record->planned_start_date) {
            return;
        }

        if ($this->record->isInProgress() || $this->record->isCompleted() || $this->record->isCancelled()) {
            Notification::make()
                ->title(__('Replanification bloquée'))
                ->body(__('Une vague en cours, terminée ou annulée ne peut pas être replanifiée.'))
                ->warning()
                ->send();

            return;
        }

        $summary = app(WaveProductionPlanningService::class)->rescheduleWaveProductions(
            wave: $this->record,
            startDate: $this->record->planned_start_date,
            skipWeekends: true,
            skipHolidays: true,
            fallbackDailyCapacity: 4,
        );

        if ($summary['planned_count'] <= 0) {
            Notification::make()
                ->title(__('Aucune production à replanifier'))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('Planification recalculée'))
            ->body(__('Les dates des batchs ont été recalculées depuis le :date.', [
                'date' => (string) ($summary['planned_start_date'] ?? ''),
            ]))
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        if ($this->shouldBlockEditContentForPresenceLock()) {
            return [];
        }

        return [
            Action::make('decideWaveStockReserve')
                ->label(__('Décider la réserve stock'))
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->visible(fn (): bool => $this->record->productions()->exists())
                ->modalDescription(__('Décidez ingrédient par ingrédient la part du stock disponible à garder en réserve pour les urgences. Le reste devient mobilisable pour cette vague dans le calcul du reste à commander.'))
                ->schema([
                    Select::make('ingredient_id')
                        ->label(__('Ingrédient'))
                        ->options(fn (): array => $this->getStockDecisionIngredientOptions())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),
                    Placeholder::make('stock_context')
                        ->label(__('Contexte'))
                        ->content(fn (Get $get): string => $this->getStockDecisionContextSummary((int) ($get('ingredient_id') ?? 0))),
                    TextInput::make('reserved_quantity')
                        ->label(__('Stock à garder en réserve'))
                        ->numeric()
                        ->default(0)
                        ->required()
                        ->helperText(fn (Get $get): string => $this->getStockDecisionInputHelper((int) ($get('ingredient_id') ?? 0))),
                ])
                ->action(function (array $data): void {
                    $ingredientId = (int) ($data['ingredient_id'] ?? 0);
                    $line = $this->getPlanningLineForIngredient($ingredientId);

                    if (! $line) {
                        Notification::make()
                            ->title(__('Ingrédient introuvable'))
                            ->warning()
                            ->send();

                        return;
                    }

                    $displayUnit = (string) ($line->display_unit ?? 'kg');
                    $availableStock = round((float) ($line->available_stock ?? 0), 3);
                    $reservedQuantity = round((float) ($data['reserved_quantity'] ?? 0), 3);

                    if ($reservedQuantity < 0) {
                        Notification::make()
                            ->title(__('Quantité invalide'))
                            ->body(__('La réserve stock ne peut pas être négative.'))
                            ->warning()
                            ->send();

                        return;
                    }

                    if ($displayUnit === 'u' && abs($reservedQuantity - round($reservedQuantity)) > 0.0001) {
                        Notification::make()
                            ->title(__('Quantité invalide'))
                            ->body(__('La réserve stock doit être un nombre entier pour les ingrédients unitaires.'))
                            ->warning()
                            ->send();

                        return;
                    }

                    if ($reservedQuantity > $availableStock) {
                        Notification::make()
                            ->title(__('Réserve trop élevée'))
                            ->body(__('La réserve ne peut pas dépasser le stock disponible pour cet ingrédient.'))
                            ->warning()
                            ->send();

                        return;
                    }

                    if ($reservedQuantity <= 0) {
                        ProductionWaveStockDecision::query()
                            ->where('production_wave_id', $this->record->id)
                            ->where('ingredient_id', $ingredientId)
                            ->get()
                            ->each
                            ->delete();
                    } else {
                        ProductionWaveStockDecision::query()->updateOrCreate(
                            [
                                'production_wave_id' => $this->record->id,
                                'ingredient_id' => $ingredientId,
                            ],
                            [
                                'reserved_quantity' => $displayUnit === 'u'
                                    ? (float) round($reservedQuantity)
                                    : $reservedQuantity,
                            ],
                        );
                    }

                    $this->record->refresh();
                    $updatedLine = $this->getPlanningLineForIngredient($ingredientId);
                    $service = app(WaveProcurementService::class);

                    Notification::make()
                        ->title(__('Réserve stock enregistrée'))
                        ->body(__('Réserve: :reserve | Stock vague: :planned | Reste à commander: :toOrder', [
                            'reserve' => $service->formatPlanningQuantity((float) ($updatedLine->reserved_stock_quantity ?? 0), $displayUnit),
                            'planned' => $service->formatPlanningQuantity((float) ($updatedLine->planned_stock_quantity ?? 0), $displayUnit),
                            'toOrder' => $service->formatPlanningQuantity((float) ($updatedLine->remaining_to_order ?? 0), $displayUnit),
                        ]))
                        ->success()
                        ->send();
                }),
            Action::make('allocateWaveIngredientStock')
                ->label(__('Allouer stock ingrédient'))
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('success')
                ->visible(fn (): bool => $this->record->productions()->exists())
                ->modalDescription(__('Alloue de vrais lots déjà en stock à la vague, ingrédient par ingrédient. Laisser la quantité vide pour allouer tout le stock vague actuellement recommandé.'))
                ->schema([
                    Select::make('ingredient_id')
                        ->label(__('Ingrédient'))
                        ->options(fn (): array => $this->getStockDecisionIngredientOptions())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),
                    Placeholder::make('allocation_context')
                        ->label(__('Contexte allocation'))
                        ->content(fn (Get $get): string => $this->getAllocationContextSummary((int) ($get('ingredient_id') ?? 0))),
                    TextInput::make('allocation_quantity')
                        ->label(__('Quantité à allouer maintenant'))
                        ->numeric()
                        ->helperText(fn (Get $get): string => $this->getAllocationInputHelper((int) ($get('ingredient_id') ?? 0))),
                ])
                ->action(function (array $data): void {
                    $ingredientId = (int) ($data['ingredient_id'] ?? 0);
                    $ingredient = Ingredient::query()->find($ingredientId);
                    $line = $this->getPlanningLineForIngredient($ingredientId);

                    if (! $ingredient || ! $line) {
                        Notification::make()
                            ->title(__('Ingrédient introuvable'))
                            ->warning()
                            ->send();

                        return;
                    }

                    $requestedQuantity = filled($data['allocation_quantity'] ?? null)
                        ? round((float) $data['allocation_quantity'], 3)
                        : round((float) ($line->planned_stock_quantity ?? 0), 3);

                    if ($requestedQuantity <= 0) {
                        Notification::make()
                            ->title(__('Aucune quantité à allouer'))
                            ->body(__('Renseignez une quantité ou définissez d\'abord un stock vague mobilisable pour cet ingrédient.'))
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $summary = app(ProductionAllocationService::class)->allocateWaveIngredientStock(
                            $this->record,
                            $ingredient,
                            $requestedQuantity,
                        );
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()
                            ->title(__('Allocation impossible'))
                            ->body($exception->getMessage())
                            ->warning()
                            ->send();

                        return;
                    }

                    $this->record->refresh();
                    $updatedLine = $this->getPlanningLineForIngredient($ingredientId);
                    $service = app(WaveProcurementService::class);
                    $displayUnit = (string) ($line->display_unit ?? 'kg');

                    Notification::make()
                        ->title(__('Allocation enregistrée'))
                        ->body(__('Alloué: :allocated | Restant sur la demande: :remaining | Déjà alloué sur la vague: :waveAllocated', [
                            'allocated' => $service->formatPlanningQuantity((float) ($summary['allocated_quantity'] ?? 0), $displayUnit),
                            'remaining' => $service->formatPlanningQuantity((float) ($summary['remaining_quantity'] ?? 0), $displayUnit),
                            'waveAllocated' => $service->formatPlanningQuantity((float) ($updatedLine->allocated_quantity ?? 0), $displayUnit),
                        ]))
                        ->success()
                        ->send();
                }),
            Action::make('markWaveItemsOrdered')
                ->label(__('Marquer les items commandés'))
                ->icon('heroicon-o-check-badge')
                ->color('info')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->productions()->exists())
                ->modalDescription(__('Marque automatiquement les items "Non commandé" couverts par des quantités engagées sur des commandes déjà passées pour cette vague.'))
                ->action(function (): void {
                    $updatedCount = app(WaveRequirementStatusService::class)->markItemsAsOrderedFromPlacedWaveOrders($this->record);

                    if ($updatedCount === 0) {
                        Notification::make()
                            ->title(__('Aucun item commandé à marquer'))
                            ->body(__('Aucun item "Non commandé" n\'est actuellement couvert par une commande passée engagée pour cette vague.'))
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('Items marqués'))
                        ->body(__('Items mis à jour: :count', ['count' => $updatedCount]))
                        ->success()
                        ->send();
                }),
            Action::make('hardDeleteWave')
                ->label(__('Supprimer définitivement'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => ! $this->record->isInProgress() && ! $this->record->isCompleted() && (auth()->user()?->canDeleteWaves() ?? false))
                ->authorize(fn (): bool => auth()->user()?->canDeleteWaves() ?? false)
                ->modalDescription(__('Supprime définitivement la vague et ses productions. Les allocations doivent être désallouées et les engagements PO retirés manuellement.'))
                ->action(function (): void {
                    try {
                        app(WaveDeletionService::class)->hardDeleteWaveWithProductions($this->record);

                        Notification::make()
                            ->title(__('Vague supprimée définitivement'))
                            ->success()
                            ->send();

                        $this->redirect(ProductionWaveResource::getUrl('index'));
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()
                            ->title(__('Suppression impossible'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function getStockDecisionIngredientOptions(): array
    {
        $service = app(WaveProcurementService::class);

        return $service->getPlanningList($this->record)
            ->mapWithKeys(function (object $line) use ($service): array {
                $unit = (string) ($line->display_unit ?? 'kg');

                return [
                    (int) $line->ingredient_id => __(':ingredient | Stock dispo :stock | Reste :remaining', [
                        'ingredient' => (string) ($line->ingredient_name ?? __('Ingrédient')),
                        'stock' => $service->formatPlanningQuantity((float) ($line->available_stock ?? 0), $unit),
                        'remaining' => $service->formatPlanningQuantity((float) ($line->remaining_requirement ?? 0), $unit),
                    ]),
                ];
            })
            ->all();
    }

    private function getStockDecisionContextSummary(int $ingredientId): string
    {
        $line = $this->getPlanningLineForIngredient($ingredientId);

        if (! $line) {
            return __('Choisissez un ingrédient pour voir le contexte de stock et de commande.');
        }

        $service = app(WaveProcurementService::class);
        $unit = (string) ($line->display_unit ?? 'kg');

        return implode(' | ', [
            __('Besoin restant: :value', ['value' => $service->formatPlanningQuantity((float) ($line->remaining_requirement ?? 0), $unit)]),
            __('Stock dispo: :value', ['value' => $service->formatPlanningQuantity((float) ($line->available_stock ?? 0), $unit)]),
            __('Réserve actuelle: :value', ['value' => $service->formatPlanningQuantity((float) ($line->reserved_stock_quantity ?? 0), $unit)]),
            __('Stock vague: :value', ['value' => $service->formatPlanningQuantity((float) ($line->planned_stock_quantity ?? 0), $unit)]),
            __('Reste à commander: :value', ['value' => $service->formatPlanningQuantity((float) ($line->remaining_to_order ?? 0), $unit)]),
        ]);
    }

    private function getStockDecisionInputHelper(int $ingredientId): string
    {
        $line = $this->getPlanningLineForIngredient($ingredientId);

        if (! $line) {
            return __('Entrez la quantité de stock à garder en réserve. Laisser 0 pour utiliser tout le stock disponible.');
        }

        $service = app(WaveProcurementService::class);
        $unit = (string) ($line->display_unit ?? 'kg');

        return __('Maximum: :value. Laisser 0 pour ne garder aucune réserve sur cet ingrédient.', [
            'value' => $service->formatPlanningQuantity((float) ($line->available_stock ?? 0), $unit),
        ]);
    }

    private function getAllocationContextSummary(int $ingredientId): string
    {
        $line = $this->getPlanningLineForIngredient($ingredientId);

        if (! $line) {
            return __('Choisissez un ingrédient pour voir le stock réellement mobilisable et l\'allocation déjà faite.');
        }

        $service = app(WaveProcurementService::class);
        $unit = (string) ($line->display_unit ?? 'kg');

        return implode(' | ', [
            __('Déjà alloué: :value', ['value' => $service->formatPlanningQuantity((float) ($line->allocated_quantity ?? 0), $unit)]),
            __('Besoin restant: :value', ['value' => $service->formatPlanningQuantity((float) ($line->remaining_requirement ?? 0), $unit)]),
            __('Stock dispo: :value', ['value' => $service->formatPlanningQuantity((float) ($line->available_stock ?? 0), $unit)]),
            __('Stock vague: :value', ['value' => $service->formatPlanningQuantity((float) ($line->planned_stock_quantity ?? 0), $unit)]),
        ]);
    }

    private function getAllocationInputHelper(int $ingredientId): string
    {
        $line = $this->getPlanningLineForIngredient($ingredientId);

        if (! $line) {
            return __('Laisser vide pour allouer tout le stock vague recommandé actuellement.');
        }

        $service = app(WaveProcurementService::class);
        $unit = (string) ($line->display_unit ?? 'kg');

        return __('Laisser vide pour allouer :value. Vous pouvez aussi saisir une quantité plus faible si la réception est partielle.', [
            'value' => $service->formatPlanningQuantity((float) ($line->planned_stock_quantity ?? 0), $unit),
        ]);
    }

    private function getPlanningLineForIngredient(int $ingredientId): ?object
    {
        if ($ingredientId <= 0) {
            return null;
        }

        return app(WaveProcurementService::class)
            ->getPlanningList($this->record)
            ->first(fn (object $line): bool => (int) $line->ingredient_id === $ingredientId);
    }
}
