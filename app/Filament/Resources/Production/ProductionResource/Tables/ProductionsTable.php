<?php

namespace App\Filament\Resources\Production\ProductionResource\Tables;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Supply\Ingredient;
use App\Services\Production\PermanentBatchNumberService;
use App\Services\Production\PlanningBatchNumberService;
use App\Services\Production\ProductionAllocationService;
use App\Services\Production\ProductionStatusTransitionService;
use App\Services\Production\StatusColorScheme;
use App\Services\Production\WaveProcurementService;
use App\Services\Production\WaveProductionPlanningService;
use App\Services\Production\WaveRequirementStatusService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Productions table configuration.
 *
 * This class encapsulates all table-related configuration for the Production resource,
 * following Filament v5 best practices of extracting table definitions from resources.
 */
class ProductionsTable
{
    private const PROCUREMENT_ACTIONABLE_STATUSES = [
        ProductionStatus::Planned,
        ProductionStatus::Confirmed,
        ProductionStatus::Ongoing,
    ];

    /**
     * Configure the productions table.
     *
     * @param  Table  $table  The table instance to configure
     * @return Table The configured table
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produit')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('production_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('permanent_batch_number')
                    ->label('Batch permanent')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('batch_number')
                    ->label('Batch planif')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('wave.name')
                    ->label('Vague')
                    ->badge()
                    ->placeholder('Autonome')
                    ->sortable(),
                TextColumn::make('productionLine.name')
                    ->label('Ligne')
                    ->badge()
                    ->placeholder('Non affectée')
                    ->sortable(),
                TextColumn::make('composite_status')
                    ->label('État')
                    ->state(fn (Production $record): string => StatusColorScheme::forProduction($record)['label'])
                    ->badge()
                    ->color(fn (Production $record): string => StatusColorScheme::forProduction($record)['color'])
                    ->icon(fn (Production $record): ?Heroicon => StatusColorScheme::forProduction($record)['icon'])
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('status', $direction))
                    ->tooltip(fn (Production $record): string => sprintf(
                        'Statut: %s | Appro: %s',
                        $record->status->getLabel(),
                        $record->getSupplyCoverageLabel()
                    )),
                TextColumn::make('planned_quantity')
                    ->label('Quantité planifiée')
                    ->numeric()
                    ->suffix(' kg')
                    ->sortable(),
                TextColumn::make('expected_units')
                    ->label('Unités attendues')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_masterbatch')
                    ->label('MB')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedBeaker)
                    ->falseIcon(Heroicon::OutlinedMinus),
                IconColumn::make('uses_masterbatch')
                    ->label('Utilise MB')
                    ->boolean()
                    ->getStateUsing(fn (Production $record): bool => $record->masterbatch_lot_id !== null)
                    ->trueIcon(Heroicon::OutlinedLink),
            ])
            ->filters([
                SelectFilter::make('production_wave_id')
                    ->label('Vague')
                    ->relationship('wave', 'name'),
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(ProductionStatus::class),
                SelectFilter::make('production_line_id')
                    ->label('Ligne')
                    ->relationship('productionLine', 'name'),
            ])
            ->recordActions([
                Action::make('confirmProduction')
                    ->label(__('Confirmer'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Production $record): bool => $record->status === ProductionStatus::Planned && (auth()->user()?->canManageProductionPlanning() ?? false))
                    ->authorize(fn (): bool => auth()->user()?->canManageProductionPlanning() ?? false)
                    ->action(function (Production $record): void {
                        $summary = app(ProductionStatusTransitionService::class)
                            ->confirmPlannedProductions(collect([$record]));

                        self::sendConfirmationNotification($summary);
                    }),
                Action::make('duplicate')
                    ->label('Dupliquer')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(fn (Production $record) => self::duplicateProduction($record)),
                ActionGroup::make([
                    Action::make('markOrphanItemsOrdered')
                        ->label(__('Marquer commandé'))
                        ->icon(Heroicon::OutlinedCheckBadge)
                        ->color('info')
                        ->slideOver()
                        ->schema([
                            Select::make('ingredient_ids')
                                ->label(__('Ingrédients'))
                                ->options(fn (Production $record): array => self::getOrphanIngredientOptions($record))
                                ->default(fn (Production $record): array => self::getDefaultOrphanIngredientIds($record))
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required(),
                            Placeholder::make('ordering_context')
                                ->label(__('Contexte'))
                                ->content(fn (Production $record, Get $get): string => self::getOrphanOrderingContextSummary(
                                    $record,
                                    collect($get('ingredient_ids') ?? [])->map(fn (mixed $ingredientId): int => (int) $ingredientId)->all(),
                                )),
                        ])
                        ->modalDescription(__('Marque manuellement comme commandés les ingrédients orphelins pris en charge hors commande liée à une vague.'))
                        ->visible(fn (Production $record): bool => self::canManageOrphanProcurement($record))
                        ->authorize(fn (): bool => auth()->user()?->canManageProductionPlanning() ?? false)
                        ->action(function (Production $record, array $data): void {
                            $updatedCount = app(WaveRequirementStatusService::class)
                                ->markNotOrderedItemsAsOrderedForProductionIngredients($record, $data['ingredient_ids'] ?? []);

                            if ($updatedCount === 0) {
                                Notification::make()
                                    ->title(__('Aucun item à marquer'))
                                    ->body(__('Les ingrédients sélectionnés sont déjà pris en charge, alloués ou non concernés.'))
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
                    Action::make('allocateOrphanIngredientStock')
                        ->label(__('Allouer stock'))
                        ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                        ->color('success')
                        ->slideOver()
                        ->schema([
                            Select::make('ingredient_id')
                                ->label(__('Ingrédient'))
                                ->options(fn (Production $record): array => self::getOrphanIngredientOptions($record))
                                ->default(fn (Production $record): ?int => self::getDefaultOrphanIngredientId($record))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required(),
                            Placeholder::make('allocation_context')
                                ->label(__('Contexte allocation'))
                                ->content(fn (Production $record, Get $get): string => self::getOrphanAllocationContextSummary(
                                    $record,
                                    (int) ($get('ingredient_id') ?? 0),
                                )),
                            TextInput::make('allocation_quantity')
                                ->label(__('Quantité à allouer maintenant'))
                                ->numeric()
                                ->helperText(fn (Production $record, Get $get): string => self::getOrphanAllocationInputHelper(
                                    $record,
                                    (int) ($get('ingredient_id') ?? 0),
                                )),
                        ])
                        ->modalDescription(__('Alloue du stock réel déjà disponible à cette production orpheline, ingrédient par ingrédient. Laisser vide pour allouer tout le besoin restant de cet ingrédient.'))
                        ->visible(fn (Production $record): bool => self::canManageOrphanProcurement($record))
                        ->authorize(fn (): bool => auth()->user()?->canManageProductionPlanning() ?? false)
                        ->action(function (Production $record, array $data): void {
                            $ingredientId = (int) ($data['ingredient_id'] ?? self::getDefaultOrphanIngredientId($record) ?? 0);
                            $ingredient = Ingredient::query()->find($ingredientId);
                            $line = self::getOrphanPlanningLine($record, $ingredientId);

                            if (! $ingredient) {
                                Notification::make()
                                    ->title(__('Ingrédient introuvable'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $requestedQuantity = filled($data['allocation_quantity'] ?? null)
                                ? round((float) $data['allocation_quantity'], 3)
                                : round((float) ($line->remaining_requirement ?? 0), 3);

                            if ($requestedQuantity <= 0) {
                                Notification::make()
                                    ->title(__('Aucune quantité à allouer'))
                                    ->body(__('Cette production n\'a plus de besoin restant pour cet ingrédient.'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            try {
                                $summary = app(ProductionAllocationService::class)->allocateProductionIngredientStock(
                                    $record,
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

                            $updatedLine = self::getOrphanPlanningLine($record->fresh(), $ingredientId);
                            $service = app(WaveProcurementService::class);
                            $displayUnit = (string) ($updatedLine->display_unit ?? $line->display_unit ?? ($ingredient->base_unit?->value ?? 'kg'));

                            Notification::make()
                                ->title(__('Allocation enregistrée'))
                                ->body(__('Alloué: :allocated | Restant sur la demande: :remaining | Déjà alloué sur la production: :productionAllocated', [
                                    'allocated' => $service->formatPlanningQuantity((float) ($summary['allocated_quantity'] ?? 0), $displayUnit),
                                    'remaining' => $service->formatPlanningQuantity((float) ($summary['remaining_quantity'] ?? 0), $displayUnit),
                                    'productionAllocated' => $service->formatPlanningQuantity((float) ($updatedLine->allocated_quantity ?? 0), $displayUnit),
                                ]))
                                ->success()
                                ->send();
                        }),
                ])
                    ->label(__('Appro orpheline'))
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->button()
                    ->visible(fn (Production $record): bool => self::canManageOrphanProcurement($record)),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('assignPermanentBatchNumbers')
                    ->label('Attribuer lots permanents')
                    ->icon(Heroicon::OutlinedHashtag)
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => self::assignPermanentBatchNumbers($records)),
                BulkAction::make('printSelectedDocuments')
                    ->label('Imprimer fiches sélectionnées')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (Collection $selectedRecords): string => self::getBulkDocumentsUrl($selectedRecords))
                    ->openUrlInNewTab(),
                BulkAction::make('rescheduleSelected')
                    ->label('Replanifier sélection')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->authorize(fn (): bool => auth()->user()?->canManageProductionPlanning() ?? false)
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Nouveau départ')
                            ->native(false)
                            ->required()
                            ->default(now()->toDateString()),
                        TextInput::make('fallback_daily_capacity')
                            ->label('Capacité / jour sans ligne')
                            ->numeric()
                            ->minValue(1)
                            ->default(4)
                            ->required(),
                        Toggle::make('skip_weekends')
                            ->label('Ignorer weekends')
                            ->default(true),
                        Toggle::make('skip_holidays')
                            ->label('Ignorer jours fériés')
                            ->default(true),
                    ])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data): void {
                        $summary = app(WaveProductionPlanningService::class)->rescheduleProductions(
                            productions: $records,
                            startDate: (string) $data['start_date'],
                            skipWeekends: (bool) ($data['skip_weekends'] ?? true),
                            skipHolidays: (bool) ($data['skip_holidays'] ?? true),
                            fallbackDailyCapacity: max(1, (int) ($data['fallback_daily_capacity'] ?? 4)),
                        );

                        Notification::make()
                            ->title('Productions replanifiées')
                            ->body(sprintf(
                                '%d replanifiée(s), %d ignorée(s).',
                                (int) $summary['rescheduled_count'],
                                (int) $summary['skipped_count'],
                            ))
                            ->success()
                            ->send();
                    }),
                BulkAction::make('confirmSelected')
                    ->label(__('Confirmer sélection'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->authorize(fn (): bool => auth()->user()?->canManageProductionPlanning() ?? false)
                    ->action(function (Collection $records): void {
                        $summary = app(ProductionStatusTransitionService::class)
                            ->confirmPlannedProductions($records);

                        self::sendConfirmationNotification($summary);
                    }),
                DeleteBulkAction::make()
                    ->label(__('Supprimer définitivement'))
                    ->modalDescription(__('Supprime définitivement les productions sélectionnées avant démarrage.'))
                    ->authorize(fn (): bool => auth()->user()?->canDeleteProductionRuns() ?? false)
                    ->authorizeIndividualRecords(fn (Production $record): bool => $record->canBeDeleted()),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['productionItems', 'productionLine']))
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Duplicate a production record with a new batch number.
     *
     * Creates a copy of the production with reset status and new identifiers.
     *
     * @param  Production  $record  The production to duplicate
     */
    private static function duplicateProduction(Production $record): void
    {
        $duplicate = $record->replicate();
        $duplicate->status = ProductionStatus::Planned;
        $duplicate->actual_units = null;
        $duplicate->permanent_batch_number = null;
        $duplicate->batch_number = app(PlanningBatchNumberService::class)->generateNextReference();
        $duplicate->slug = self::generateDuplicatedSlug($duplicate->batch_number);
        $duplicate->save();

        Notification::make()
            ->title('Production dupliquée')
            ->body('Nouveau batch: '.$duplicate->batch_number)
            ->success()
            ->send();
    }

    /**
     * Generate a unique slug for a duplicated production.
     *
     * Ensures the slug is unique by appending a numeric suffix if necessary.
     *
     * @param  string  $batchNumber  The batch number to base the slug on
     * @return string The unique slug
     */
    private static function generateDuplicatedSlug(string $batchNumber): string
    {
        $base = Str::slug($batchNumber);
        $slug = $base;
        $attempt = 1;

        while (Production::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.str_pad((string) $attempt, 2, '0', STR_PAD_LEFT);
            $attempt++;
        }

        return $slug;
    }

    /**
     * Assign permanent batch numbers to multiple productions.
     *
     * @param  Collection  $records  The productions to process
     */
    private static function assignPermanentBatchNumbers(Collection $records): void
    {
        $assigned = app(PermanentBatchNumberService::class)
            ->assignForProductions($records->pluck('id')->all());

        Notification::make()
            ->title('Lots permanents attribués')
            ->body($assigned.' lot(s) permanent(s) attribué(s).')
            ->success()
            ->send();
    }

    /**
     * @param  array{confirmed: int, skipped: int, failed: int}  $summary
     */
    private static function sendConfirmationNotification(array $summary): void
    {
        $confirmed = (int) ($summary['confirmed'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);

        $notification = Notification::make()
            ->title(__('Confirmation productions'))
            ->body(__('Confirmées: :confirmed | Ignorées: :skipped | Erreurs: :failed', [
                'confirmed' => $confirmed,
                'skipped' => $skipped,
                'failed' => $failed,
            ]));

        if ($confirmed > 0 && $failed === 0) {
            $notification->success()->send();

            return;
        }

        if ($failed > 0) {
            $notification->danger()->send();

            return;
        }

        $notification->warning()->send();
    }

    /**
     * Generate the URL for bulk document printing.
     *
     * @param  Collection  $selectedRecords  The productions to include in the print
     * @return string The URL for the bulk documents route
     */
    private static function getBulkDocumentsUrl(Collection $selectedRecords): string
    {
        $ids = $selectedRecords
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->implode(',');

        return route('productions.bulk-documents', ['ids' => $ids]);
    }

    private static function canManageOrphanProcurement(Production $record): bool
    {
        return $record->isOrphan()
            && in_array($record->status, self::PROCUREMENT_ACTIONABLE_STATUSES, true)
            && (auth()->user()?->canManageProductionPlanning() ?? false);
    }

    /**
     * @return array<int, string>
     */
    private static function getOrphanIngredientOptions(Production $record): array
    {
        $service = app(WaveProcurementService::class);

        return $service->getPlanningListForProduction($record)
            ->mapWithKeys(function (object $line) use ($service): array {
                $unit = (string) ($line->display_unit ?? 'kg');

                return [
                    (int) $line->ingredient_id => __(':ingredient | Reste :remaining | Stock :stock', [
                        'ingredient' => (string) ($line->ingredient_name ?? __('Ingrédient')),
                        'remaining' => $service->formatPlanningQuantity((float) ($line->remaining_requirement ?? 0), $unit),
                        'stock' => $service->formatPlanningQuantity((float) ($line->available_stock ?? 0), $unit),
                    ]),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, int>  $ingredientIds
     */
    private static function getOrphanOrderingContextSummary(Production $record, array $ingredientIds): string
    {
        if ($ingredientIds === []) {
            return __('Choisissez un ou plusieurs ingrédients pour voir le besoin restant à couvrir.');
        }

        $service = app(WaveProcurementService::class);
        $lines = $service->getPlanningListForProduction($record)
            ->whereIn('ingredient_id', $ingredientIds);

        if ($lines->isEmpty()) {
            return __('Aucun besoin actif trouvé pour les ingrédients sélectionnés.');
        }

        return __('Besoin restant: :remaining | Stock dispo: :stock | Déjà alloué: :allocated', [
            'remaining' => $service->formatPlanningQuantityByUnit($lines, 'remaining_requirement'),
            'stock' => $service->formatPlanningQuantityByUnit($lines, 'available_stock'),
            'allocated' => $service->formatPlanningQuantityByUnit($lines, 'allocated_quantity'),
        ]);
    }

    private static function getOrphanAllocationContextSummary(Production $record, int $ingredientId): string
    {
        $line = self::getOrphanPlanningLine($record, $ingredientId);

        if (! $line) {
            return __('Choisissez un ingrédient pour voir le besoin restant et le stock disponible.');
        }

        $service = app(WaveProcurementService::class);
        $unit = (string) ($line->display_unit ?? 'kg');

        return __('Besoin restant: :remaining | Déjà alloué: :allocated | Stock dispo: :stock | Date besoin: :needDate', [
            'remaining' => $service->formatPlanningQuantity((float) ($line->remaining_requirement ?? 0), $unit),
            'allocated' => $service->formatPlanningQuantity((float) ($line->allocated_quantity ?? 0), $unit),
            'stock' => $service->formatPlanningQuantity((float) ($line->available_stock ?? 0), $unit),
            'needDate' => $line->need_date
                ? Carbon::parse((string) $line->need_date)->format('d/m/Y')
                : __('Non définie'),
        ]);
    }

    private static function getOrphanAllocationInputHelper(Production $record, int $ingredientId): string
    {
        $line = self::getOrphanPlanningLine($record, $ingredientId);

        if (! $line) {
            return __('Choisissez un ingrédient pour voir la quantité recommandée.');
        }

        $service = app(WaveProcurementService::class);
        $unit = (string) ($line->display_unit ?? 'kg');

        return __('Laisser vide pour tenter d\'allouer tout le besoin restant (:remaining). L\'allocation automatique reste stricte: un item n\'est servi que s\'il peut être couvert en totalité.', [
            'remaining' => $service->formatPlanningQuantity((float) ($line->remaining_requirement ?? 0), $unit),
        ]);
    }

    private static function getOrphanPlanningLine(Production $record, int $ingredientId): ?object
    {
        if ($ingredientId <= 0) {
            return null;
        }

        return app(WaveProcurementService::class)
            ->getPlanningListForProduction($record)
            ->first(fn (object $line): bool => (int) ($line->ingredient_id ?? 0) === $ingredientId);
    }

    private static function getDefaultOrphanIngredientId(Production $record): ?int
    {
        $firstIngredientId = array_key_first(self::getOrphanIngredientOptions($record));

        return $firstIngredientId !== null ? (int) $firstIngredientId : null;
    }

    /**
     * @return array<int, int>
     */
    private static function getDefaultOrphanIngredientIds(Production $record): array
    {
        $defaultIngredientId = self::getDefaultOrphanIngredientId($record);

        return $defaultIngredientId !== null ? [$defaultIngredientId] : [];
    }
}
