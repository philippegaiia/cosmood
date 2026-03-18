<?php

namespace App\Filament\Resources\Supply\SupplyResource\Tables;

use App\Enums\ProductionStatus;
use App\Enums\WaveStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SuppliesMovement;
use App\Models\Supply\Supply;
use App\Services\Production\ProductionAllocationService;
use App\Services\Production\WaveProcurementService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Supplies table configuration.
 *
 * This class encapsulates all table-related configuration for the Supply resource,
 * following Filament v5 best practices of extracting table definitions from resources.
 */
class SuppliesTable
{
    private const PROCUREMENT_ACTIONABLE_PRODUCTION_STATUSES = [
        ProductionStatus::Planned,
        ProductionStatus::Confirmed,
        ProductionStatus::Ongoing,
    ];

    private const PROCUREMENT_ACTIONABLE_WAVE_STATUSES = [
        WaveStatus::Draft,
        WaveStatus::Approved,
        WaveStatus::InProgress,
    ];

    /**
     * Configure the supplies table.
     *
     * @param  Table  $table  The table instance to configure
     * @return Table The configured table
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'supplierListing.ingredient',
                    'supplierListing.supplier',
                    'sourceProduction.product',
                    'supplierOrderItem.supplierOrder.wave',
                    'supplierOrderItem.allocatedToProduction.product',
                ])
                ->withSum([
                    'movements as '.Supply::ALLOCATED_QUANTITY_SUM_ATTRIBUTE => fn (Builder $movementQuery): Builder => $movementQuery
                        ->where('movement_type', 'allocation'),
                ], 'quantity'))
            ->columns([
                TextColumn::make('supplierListing.ingredient.name')
                    ->label('Ingrédient')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->icon(fn (Supply $record): ?Heroicon => self::getIngredientAlertIcon($record)),

                TextColumn::make('batch_number')
                    ->label('Lot')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('source')
                    ->label('Source')
                    ->state(fn (Supply $record): string => $record->source_production_id !== null ? 'Interne' : 'Achat')
                    ->badge()
                    ->color(fn (Supply $record): string => $record->source_production_id !== null ? 'info' : 'gray')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('CASE WHEN source_production_id IS NULL THEN 0 ELSE 1 END '.$direction)),

                TextColumn::make('source_reference')
                    ->label('Réf source')
                    ->state(fn (Supply $record): string => $record->source_production_id !== null
                        ? ($record->sourceProduction?->getLotDisplayLabel() ?? '-')
                        : ($record->order_ref ?? '-'))
                    ->toggleable(isToggledHiddenByDefault: true),

                ViewColumn::make('stock_availability')
                    ->label('Stock disponible')
                    ->view('components.stock-meter')
                    ->getStateUsing(function (Supply $record): array {
                        $available = $record->getAvailableQuantity();
                        $total = $record->getTotalQuantity();
                        $allocated = $record->getAllocatedQuantity();
                        $ingredient = $record->supplierListing?->ingredient;
                        $minStock = $ingredient?->stock_min ?? null;
                        $isBelowMin = $minStock !== null && $minStock > 0 && $available < $minStock;

                        return [
                            'available' => $available,
                            'allocated' => $allocated,
                            'total' => $total,
                            'unit' => $record->getUnitOfMeasure(),
                            'min_stock' => $minStock,
                            'is_below_min' => $isBelowMin,
                        ];
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0) - COALESCE('.Supply::ALLOCATED_QUANTITY_SUM_ATTRIBUTE.', 0)) '.$direction)),

                TextColumn::make('unit_price')
                    ->label('Prix unitaire')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('delivery_date')
                    ->label('Entrée')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('expiry_date')
                    ->label('DLUO')
                    ->date()
                    ->sortable()
                    ->color(fn (Supply $record): ?string => $record->expiry_date === null
                        ? null
                        : ($record->expiry_date->isPast() ? 'danger' : ($record->expiry_date->lte(now()->addDays(45)) ? 'warning' : 'success'))),

                TextColumn::make('supplierListing.supplier.name')
                    ->label('Fournisseur')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('supplierListing.name')
                    ->label('Réf fournisseur')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_in_stock')
                    ->label('En stock')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_used_at')
                    ->label('Dernière utilisation')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->placeholder('Jamais utilisé')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('ingredient')
                    ->label('Ingrédient')
                    ->relationship('supplierListing.ingredient', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        'purchase' => 'Achat',
                        'internal' => 'Interne',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'purchase' => $query->whereNull('source_production_id'),
                            'internal' => $query->whereNotNull('source_production_id'),
                            default => $query,
                        };
                    }),
                TernaryFilter::make('is_in_stock')
                    ->label('En stock'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),

                    Action::make('adjust')
                        ->label('Ajuster')
                        ->icon(Heroicon::AdjustmentsHorizontal)
                        ->color('warning')
                        ->visible(fn (): bool => auth()->user()?->canManageSupplyInventory() ?? false)
                        ->schema([
                            TextInput::make('adjustment_quantity')
                                ->label('Quantité d\'ajustement')
                                ->numeric()
                                ->step(0.001)
                                ->required()
                                ->helperText('Positive = ajout de stock, Négative = retrait de stock'),

                            DateTimePicker::make('moved_at')
                                ->label('Date et heure')
                                ->default(now())
                                ->required(),

                            Textarea::make('reason')
                                ->label('Raison de l\'ajustement')
                                ->required()
                                ->placeholder('Ex: Inventaire, correction erreur, etc.'),
                        ])
                        ->action(function (array $data, Supply $record): void {
                            if (! (auth()->user()?->canManageSupplyInventory() ?? false)) {
                                Notification::make()
                                    ->title(__('Accès refusé'))
                                    ->body(__('Vous n’avez pas l’autorisation d’ajuster ce lot.'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            SuppliesMovement::create([
                                'supply_id' => $record->id,
                                'quantity' => $data['adjustment_quantity'],
                                'movement_type' => 'adjustment',
                                'moved_at' => $data['moved_at'],
                                'reason' => $data['reason'],
                                'user_id' => auth()->id(),
                            ]);

                            // Update supply quantities based on adjustment
                            if ($data['adjustment_quantity'] > 0) {
                                $record->quantity_in = ($record->quantity_in ?? 0) + $data['adjustment_quantity'];
                            } else {
                                $record->quantity_out = ($record->quantity_out ?? 0) + abs($data['adjustment_quantity']);
                            }
                            $record->save();
                        })
                        ->successNotificationTitle('Ajustement créé'),

                    Action::make('markOutOfStock')
                        ->label('Marquer épuisé')
                        ->icon(Heroicon::ArchiveBoxXMark)
                        ->color('danger')
                        ->visible(fn (Supply $record): bool => $record->is_in_stock && (auth()->user()?->canManageSupplyInventory() ?? false))
                        ->requiresConfirmation()
                        ->modalHeading('Marquer ce lot comme épuisé?')
                        ->modalDescription('Cette action marquera le lot comme hors stock. Assurez-vous d\'avoir effectué un ajustement manuel si nécessaire.')
                        ->action(function (Supply $record): void {
                            if (! (auth()->user()?->canManageSupplyInventory() ?? false)) {
                                Notification::make()
                                    ->title(__('Accès refusé'))
                                    ->body(__('Vous n’avez pas l’autorisation de modifier le statut stock de ce lot.'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $record->update(['is_in_stock' => false]);

                            Notification::make()
                                ->title('Lot marqué comme épuisé')
                                ->body("Le lot {$record->batch_number} a été marqué comme hors stock.")
                                ->success()
                                ->send();
                        })
                        ->successNotificationTitle('Lot marqué comme épuisé'),

                    Action::make('allocateToWave')
                        ->label(__('Allouer à une vague'))
                        ->icon(Heroicon::OutlinedSquares2x2)
                        ->color('info')
                        ->slideOver()
                        ->visible(fn (Supply $record): bool => self::canQuickAllocateSupply($record))
                        ->schema([
                            Select::make('production_wave_id')
                                ->label(__('Vague'))
                                ->options(fn (Supply $record): array => self::getWaveAllocationOptions($record))
                                ->default(fn (Supply $record): ?int => self::getDefaultWaveIdForSupply($record))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required(),
                            Placeholder::make('wave_allocation_context')
                                ->label(__('Contexte allocation'))
                                ->content(fn (Supply $record, Get $get): string => self::getWaveAllocationContextSummary(
                                    $record,
                                    (int) ($get('production_wave_id') ?? self::getDefaultWaveIdForSupply($record) ?? 0),
                                )),
                            TextInput::make('allocation_quantity')
                                ->label(__('Quantité de ce lot à allouer'))
                                ->numeric()
                                ->helperText(fn (Supply $record, Get $get): string => self::getWaveAllocationInputHelper(
                                    $record,
                                    (int) ($get('production_wave_id') ?? self::getDefaultWaveIdForSupply($record) ?? 0),
                                )),
                        ])
                        ->modalDescription(__('Alloue ce lot précis à une vague, sans passer par une autre réception ou un autre lot compatible.'))
                        ->action(function (Supply $record, array $data): void {
                            $waveId = (int) ($data['production_wave_id'] ?? self::getDefaultWaveIdForSupply($record) ?? 0);
                            $wave = ProductionWave::query()->find($waveId);
                            $line = self::getWavePlanningLineForSupply($record, $waveId);

                            if (! $wave || ! $line) {
                                Notification::make()
                                    ->title(__('Vague introuvable'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $requestedQuantity = filled($data['allocation_quantity'] ?? null)
                                ? round((float) $data['allocation_quantity'], 3)
                                : round(min((float) $record->getAvailableQuantity(), (float) ($line->planned_stock_quantity ?? 0)), 3);

                            if ($requestedQuantity <= 0) {
                                Notification::make()
                                    ->title(__('Aucune quantité à allouer'))
                                    ->body(__('Ce lot ou cette vague n’a plus de besoin compatible pour cet ingrédient.'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            try {
                                $summary = app(ProductionAllocationService::class)->allocateSupplyToWave($record, $wave, $requestedQuantity);
                            } catch (\InvalidArgumentException $exception) {
                                Notification::make()
                                    ->title(__('Allocation impossible'))
                                    ->body($exception->getMessage())
                                    ->warning()
                                    ->send();

                                return;
                            }

                            if ((float) ($summary['allocated_quantity'] ?? 0) <= 0) {
                                Notification::make()
                                    ->title(__('Aucune allocation créée'))
                                    ->body(__('Mode strict: ce lot ne couvre encore entièrement aucun item de cette vague.'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $freshSupply = $record->fresh();
                            $updatedLine = self::getWavePlanningLineForSupply($freshSupply ?? $record, $waveId);
                            $displayUnit = (string) ($line->display_unit ?? $record->getUnitOfMeasure());
                            $service = app(WaveProcurementService::class);

                            Notification::make()
                                ->title(__('Lot alloué à la vague'))
                                ->body(__('Alloué: :allocated | Disponible sur le lot: :lotAvailable | Restant sur la vague: :waveRemaining', [
                                    'allocated' => $service->formatPlanningQuantity((float) ($summary['allocated_quantity'] ?? 0), $displayUnit),
                                    'lotAvailable' => $service->formatPlanningQuantity((float) ($freshSupply?->getAvailableQuantity() ?? 0), $displayUnit),
                                    'waveRemaining' => $service->formatPlanningQuantity((float) ($updatedLine->remaining_requirement ?? 0), $displayUnit),
                                ]))
                                ->success()
                                ->send();
                        }),

                    Action::make('allocateToProduction')
                        ->label(__('Allouer à une orpheline'))
                        ->icon(Heroicon::OutlinedRectangleStack)
                        ->color('success')
                        ->slideOver()
                        ->visible(fn (Supply $record): bool => self::canQuickAllocateSupply($record))
                        ->schema([
                            Select::make('production_id')
                                ->label(__('Production orpheline'))
                                ->options(fn (Supply $record): array => self::getOrphanProductionAllocationOptions($record))
                                ->default(fn (Supply $record): ?int => self::getDefaultOrphanProductionIdForSupply($record))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required(),
                            Placeholder::make('production_allocation_context')
                                ->label(__('Contexte allocation'))
                                ->content(fn (Supply $record, Get $get): string => self::getProductionAllocationContextSummary(
                                    $record,
                                    (int) ($get('production_id') ?? self::getDefaultOrphanProductionIdForSupply($record) ?? 0),
                                )),
                            TextInput::make('allocation_quantity')
                                ->label(__('Quantité de ce lot à allouer'))
                                ->numeric()
                                ->helperText(fn (Supply $record, Get $get): string => self::getProductionAllocationInputHelper(
                                    $record,
                                    (int) ($get('production_id') ?? self::getDefaultOrphanProductionIdForSupply($record) ?? 0),
                                )),
                        ])
                        ->modalDescription(__('Alloue ce lot précis à une production autonome encore en attente de cet ingrédient.'))
                        ->action(function (Supply $record, array $data): void {
                            $productionId = (int) ($data['production_id'] ?? self::getDefaultOrphanProductionIdForSupply($record) ?? 0);
                            $production = Production::query()->find($productionId);
                            $line = self::getProductionPlanningLineForSupply($record, $productionId);

                            if (! $production || ! $line) {
                                Notification::make()
                                    ->title(__('Production introuvable'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $requestedQuantity = filled($data['allocation_quantity'] ?? null)
                                ? round((float) $data['allocation_quantity'], 3)
                                : round(min((float) $record->getAvailableQuantity(), (float) ($line->remaining_after_linked_orders ?? 0)), 3);

                            if ($requestedQuantity <= 0) {
                                Notification::make()
                                    ->title(__('Aucune quantité à allouer'))
                                    ->body(__('Ce lot ou cette production n’a plus de besoin compatible pour cet ingrédient.'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            try {
                                $summary = app(ProductionAllocationService::class)->allocateSupplyToProduction($record, $production, $requestedQuantity);
                            } catch (\InvalidArgumentException $exception) {
                                Notification::make()
                                    ->title(__('Allocation impossible'))
                                    ->body($exception->getMessage())
                                    ->warning()
                                    ->send();

                                return;
                            }

                            if ((float) ($summary['allocated_quantity'] ?? 0) <= 0) {
                                Notification::make()
                                    ->title(__('Aucune allocation créée'))
                                    ->body(__('Mode strict: ce lot ne couvre encore entièrement aucun item de cette production.'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $freshSupply = $record->fresh();
                            $updatedLine = self::getProductionPlanningLineForSupply($freshSupply ?? $record, $productionId);
                            $displayUnit = (string) ($line->display_unit ?? $record->getUnitOfMeasure());
                            $service = app(WaveProcurementService::class);

                            Notification::make()
                                ->title(__('Lot alloué à la production'))
                                ->body(__('Alloué: :allocated | Disponible sur le lot: :lotAvailable | Restant sur la production: :productionRemaining', [
                                    'allocated' => $service->formatPlanningQuantity((float) ($summary['allocated_quantity'] ?? 0), $displayUnit),
                                    'lotAvailable' => $service->formatPlanningQuantity((float) ($freshSupply?->getAvailableQuantity() ?? 0), $displayUnit),
                                    'productionRemaining' => $service->formatPlanningQuantity((float) ($updatedLine->remaining_after_linked_orders ?? 0), $displayUnit),
                                ]))
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->groups([
                Group::make('supplierListing.ingredient.name')
                    ->label('Ingrédient')
                    ->collapsible(),
                Group::make('supplierListing.supplier.name')
                    ->label('Fournisseur')
                    ->collapsible(),
                Group::make('source')
                    ->label('Source')
                    ->getTitleFromRecordUsing(fn (Supply $record): string => $record->source_production_id !== null ? 'Interne' : 'Achat')
                    ->collapsible(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markOutOfStock')
                        ->label('Marquer épuisés')
                        ->icon(Heroicon::ArchiveBoxXMark)
                        ->color('danger')
                        ->visible(fn (): bool => auth()->user()?->canManageSupplyInventory() ?? false)
                        ->requiresConfirmation()
                        ->modalHeading('Marquer les lots comme épuisés?')
                        ->modalDescription('Cette action marquera tous les lots sélectionnés comme hors stock.')
                        ->action(function (Collection $records): void {
                            if (! (auth()->user()?->canManageSupplyInventory() ?? false)) {
                                Notification::make()
                                    ->title(__('Accès refusé'))
                                    ->body(__('Vous n’avez pas l’autorisation de modifier le statut stock de ces lots.'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->is_in_stock) {
                                    $record->update(['is_in_stock' => false]);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->title('Lots marqués comme épuisés')
                                ->body("{$count} lot(s) ont été marqués comme hors stock.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Get alert icon if ingredient's consolidated stock is below minimum.
     */
    private static function getIngredientAlertIcon(Supply $record): ?Heroicon
    {
        $ingredient = $record->supplierListing?->ingredient;

        if (! $ingredient || ! $ingredient->stock_min || $ingredient->stock_min <= 0) {
            return null;
        }

        $consolidatedAvailable = $ingredient->getTotalAvailableStock();

        if ($consolidatedAvailable < $ingredient->stock_min) {
            return Heroicon::ExclamationTriangle;
        }

        return null;
    }

    private static function canQuickAllocateSupply(Supply $record): bool
    {
        return $record->is_in_stock
            && round($record->getAvailableQuantity(), 3) > 0
            && (auth()->user()?->canManageProductionPlanning() ?? false);
    }

    private static function resolveSupplyIngredient(Supply $record): ?Ingredient
    {
        $record->loadMissing('supplierListing.ingredient');

        return $record->supplierListing?->ingredient;
    }

    /**
     * @return array<int, string>
     */
    private static function getWaveAllocationOptions(Supply $record): array
    {
        $ingredient = self::resolveSupplyIngredient($record);

        if (! $ingredient) {
            return [];
        }

        $service = app(WaveProcurementService::class);

        return ProductionWave::query()
            ->whereIn('status', self::PROCUREMENT_ACTIONABLE_WAVE_STATUSES)
            ->orderBy('planned_start_date')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (ProductionWave $wave) use ($ingredient, $service): array {
                $line = $service->getPlanningList($wave)
                    ->first(fn (object $entry): bool => (int) ($entry->ingredient_id ?? 0) === $ingredient->id && (float) ($entry->remaining_requirement ?? 0) > 0);

                if (! $line) {
                    return [];
                }

                return [
                    $wave->id => __(':wave | Reste :remaining | Date besoin :needDate', [
                        'wave' => (string) $wave->name,
                        'remaining' => $service->formatPlanningQuantity((float) ($line->remaining_requirement ?? 0), (string) ($line->display_unit ?? 'kg')),
                        'needDate' => $line->need_date
                            ? Carbon::parse((string) $line->need_date)->format('d/m/Y')
                            : __('Non définie'),
                    ]),
                ];
            })
            ->all();
    }

    private static function getDefaultWaveIdForSupply(Supply $record): ?int
    {
        $options = self::getWaveAllocationOptions($record);
        $linkedWaveId = (int) ($record->supplierOrderItem?->supplierOrder?->production_wave_id ?? 0);

        if ($linkedWaveId > 0 && array_key_exists($linkedWaveId, $options)) {
            return $linkedWaveId;
        }

        $firstWaveId = array_key_first($options);

        return $firstWaveId !== null ? (int) $firstWaveId : null;
    }

    private static function getWaveAllocationContextSummary(Supply $record, int $waveId): string
    {
        $line = self::getWavePlanningLineForSupply($record, $waveId);

        if (! $line) {
            return __('Choisissez une vague ayant encore un besoin actif sur cet ingrédient.');
        }

        $service = app(WaveProcurementService::class);
        $displayUnit = (string) ($line->display_unit ?? $record->getUnitOfMeasure());

        return __('Disponible sur le lot: :lotAvailable | Stock vague mobilisable: :wavePlannedStock | Déjà alloué vague: :waveAllocated', [
            'lotAvailable' => $service->formatPlanningQuantity((float) $record->getAvailableQuantity(), $displayUnit),
            'wavePlannedStock' => $service->formatPlanningQuantity((float) ($line->planned_stock_quantity ?? 0), $displayUnit),
            'waveAllocated' => $service->formatPlanningQuantity((float) ($line->allocated_quantity ?? 0), $displayUnit),
        ]);
    }

    private static function getWaveAllocationInputHelper(Supply $record, int $waveId): string
    {
        $line = self::getWavePlanningLineForSupply($record, $waveId);

        if (! $line) {
            return __('Choisissez une vague pour voir la quantité recommandée.');
        }

        $service = app(WaveProcurementService::class);
        $displayUnit = (string) ($line->display_unit ?? $record->getUnitOfMeasure());

        return __('Laisser vide pour allouer le minimum entre le disponible du lot (:lotAvailable) et le stock vague mobilisable (:wavePlannedStock). Mode strict: seuls les items entièrement couvrables sont servis.', [
            'lotAvailable' => $service->formatPlanningQuantity((float) $record->getAvailableQuantity(), $displayUnit),
            'wavePlannedStock' => $service->formatPlanningQuantity((float) ($line->planned_stock_quantity ?? 0), $displayUnit),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private static function getOrphanProductionAllocationOptions(Supply $record): array
    {
        $ingredient = self::resolveSupplyIngredient($record);

        if (! $ingredient) {
            return [];
        }

        $service = app(WaveProcurementService::class);

        return Production::query()
            ->whereNull('production_wave_id')
            ->whereIn('status', self::PROCUREMENT_ACTIONABLE_PRODUCTION_STATUSES)
            ->with('product:id,name')
            ->orderBy('production_date')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (Production $production) use ($ingredient, $service): array {
                $line = $service->getPlanningListForProduction($production)
                    ->first(fn (object $entry): bool => (int) ($entry->ingredient_id ?? 0) === $ingredient->id && (float) ($entry->remaining_after_linked_orders ?? 0) > 0);

                if (! $line) {
                    return [];
                }

                return [
                    $production->id => __(':production | Après PO :remaining | Date besoin :needDate', [
                        'production' => self::getProductionLabel($production),
                        'remaining' => $service->formatPlanningQuantity((float) ($line->remaining_after_linked_orders ?? 0), (string) ($line->display_unit ?? 'kg')),
                        'needDate' => $line->need_date
                            ? Carbon::parse((string) $line->need_date)->format('d/m/Y')
                            : __('Non définie'),
                    ]),
                ];
            })
            ->all();
    }

    private static function getDefaultOrphanProductionIdForSupply(Supply $record): ?int
    {
        $options = self::getOrphanProductionAllocationOptions($record);
        $linkedProductionId = (int) ($record->supplierOrderItem?->allocated_to_production_id ?? 0);

        if ($linkedProductionId > 0 && array_key_exists($linkedProductionId, $options)) {
            return $linkedProductionId;
        }

        $firstProductionId = array_key_first($options);

        return $firstProductionId !== null ? (int) $firstProductionId : null;
    }

    private static function getProductionAllocationContextSummary(Supply $record, int $productionId): string
    {
        $line = self::getProductionPlanningLineForSupply($record, $productionId);

        if (! $line) {
            return __('Choisissez une production autonome ayant encore un besoin actif sur cet ingrédient.');
        }

        $service = app(WaveProcurementService::class);
        $displayUnit = (string) ($line->display_unit ?? $record->getUnitOfMeasure());

        return __('Disponible sur le lot: :lotAvailable | Besoin physique: :physicalRemaining | Après PO liée: :productionRemaining | Déjà alloué production: :productionAllocated', [
            'lotAvailable' => $service->formatPlanningQuantity((float) $record->getAvailableQuantity(), $displayUnit),
            'physicalRemaining' => $service->formatPlanningQuantity((float) ($line->remaining_requirement ?? 0), $displayUnit),
            'productionRemaining' => $service->formatPlanningQuantity((float) ($line->remaining_after_linked_orders ?? 0), $displayUnit),
            'productionAllocated' => $service->formatPlanningQuantity((float) ($line->allocated_quantity ?? 0), $displayUnit),
        ]);
    }

    private static function getProductionAllocationInputHelper(Supply $record, int $productionId): string
    {
        $line = self::getProductionPlanningLineForSupply($record, $productionId);

        if (! $line) {
            return __('Choisissez une production autonome pour voir la quantité recommandée.');
        }

        $service = app(WaveProcurementService::class);
        $displayUnit = (string) ($line->display_unit ?? $record->getUnitOfMeasure());

        return __('Laisser vide pour allouer le minimum entre le disponible du lot (:lotAvailable) et le besoin encore utile après PO liée (:productionRemaining). Mode strict: seuls les items entièrement couvrables sont servis.', [
            'lotAvailable' => $service->formatPlanningQuantity((float) $record->getAvailableQuantity(), $displayUnit),
            'productionRemaining' => $service->formatPlanningQuantity((float) ($line->remaining_after_linked_orders ?? 0), $displayUnit),
        ]);
    }

    private static function getWavePlanningLineForSupply(Supply $record, int $waveId): ?object
    {
        $ingredient = self::resolveSupplyIngredient($record);

        if (! $ingredient || $waveId <= 0) {
            return null;
        }

        $wave = ProductionWave::query()->find($waveId);

        if (! $wave) {
            return null;
        }

        return app(WaveProcurementService::class)
            ->getPlanningList($wave)
            ->first(fn (object $line): bool => (int) ($line->ingredient_id ?? 0) === $ingredient->id);
    }

    private static function getProductionPlanningLineForSupply(Supply $record, int $productionId): ?object
    {
        $ingredient = self::resolveSupplyIngredient($record);

        if (! $ingredient || $productionId <= 0) {
            return null;
        }

        $production = Production::query()->find($productionId);

        if (! $production) {
            return null;
        }

        return app(WaveProcurementService::class)
            ->getPlanningListForProduction($production)
            ->first(fn (object $line): bool => (int) ($line->ingredient_id ?? 0) === $ingredient->id);
    }

    private static function getProductionLabel(Production $production): string
    {
        $batchNumber = filled($production->batch_number)
            ? (string) $production->batch_number
            : __('Production #:id', ['id' => $production->id]);
        $productName = $production->product?->name;

        if (filled($productName)) {
            return $batchNumber.' - '.$productName;
        }

        return $batchNumber;
    }

    public static function getEloquentQuery(Builder $query): Builder
    {
        return $query;
    }
}
