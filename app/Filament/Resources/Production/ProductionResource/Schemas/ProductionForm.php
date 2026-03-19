<?php

namespace App\Filament\Resources\Production\ProductionResource\Schemas;

use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Enums\WaveStatus;
use App\Models\Production\BatchSizePreset;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Production form schema configuration.
 *
 * This class encapsulates all form-related configuration for the Production resource,
 * following Filament v5 best practices of extracting form schemas from resources.
 *
 * The form is organized into three tabs:
 * - Planning: Batch identification, product selection, batch size, dates
 * - Execution: Status management, notes, masterbatch configuration
 * - Composition: Production items managed via Livewire component
 */
class ProductionForm
{
    /**
     * Configure the production form schema.
     *
     * @param  Schema  $schema  The schema instance to configure
     * @return Schema The configured schema
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('production_flow')
                    ->columnSpanFull()
                    ->tabs([
                        self::getPlanningTab(),
                        self::getExecutionTab(),
                        self::getCompositionTab(),
                    ]),
            ]);
    }

    /**
     * Build the Planning tab schema.
     *
     * Contains:
     * - Batch identification (wave, batch number, permanent batch number)
     * - Product selection (product, formula, product type, batch size preset)
     * - Batch size configuration (sizing mode, planned quantity, expected units)
     * - Date configuration (production date, ready date)
     *
     * @return Tab The configured tab
     */
    private static function getPlanningTab(): Tab
    {
        return Tab::make(__('Planification'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->schema([
                self::getBatchIdentificationSection(),
                self::getProductSelectionSection(),
                self::getBatchSizeSection(),
                self::getDatesSection(),
                self::getMasterbatchConfigSection(),
            ]);
    }

    /**
     * Build the batch identification section.
     *
     * Contains fields for wave assignment, planning reference, and permanent batch number.
     * The permanent batch number is auto-assigned when production starts.
     *
     * @return Section The configured section
     */
    private static function getBatchIdentificationSection(): Section
    {
        return Section::make(__('Lot de production'))
            ->columnSpanFull()
            ->columns([
                'default' => 1,
                'md' => 4,
            ])
            ->schema([
                Select::make('production_wave_id')
                    ->label(__('Vague de production'))
                    ->relationship(
                        name: 'wave',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query
                            ->whereIn('status', [
                                WaveStatus::Draft->value,
                                WaveStatus::Approved->value,
                            ])
                            ->orderByDesc('planned_start_date')
                            ->orderByDesc('id'),
                    )
                    ->searchable()
                    ->preload()
                    ->live()
                    ->placeholder(__('Aucune (production autonome)'))
                    ->helperText(__('Disponible uniquement pour les vagues en brouillon ou approuvées.'))
                    ->nullable(),
                Select::make('production_line_id')
                    ->label(__('Ligne de production'))
                    ->options(fn (Get $get, ?Production $record): array => self::getProductionLineOptions($get, $record))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live()
                    ->placeholder(__('Affectation automatique'))
                    ->helperText(__('Utilise la ligne par défaut du type produit si vide.'))
                    ->nullable(),
                TextInput::make('batch_number')
                    ->label(__('Réf. planification'))
                    ->helperText(__('Si vide, une référence courte est attribuée automatiquement (ex: T00001).'))
                    ->placeholder(__('Auto (T00001)'))
                    ->required(fn (string $operation): bool => $operation === 'edit')
                    ->maxLength(255)
                    ->unique(),
                TextInput::make('permanent_batch_number')
                    ->label(__('Lot permanent'))
                    ->placeholder(__('Attribué automatiquement au démarrage'))
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }

    /**
     * Build the product selection section.
     *
     * Contains fields for product, formula, product type, and batch size preset.
     * When a product is selected, it auto-populates formula and product type defaults.
     * When a product type is selected, it auto-populates sizing mode and batch size.
     *
     * @return Section The configured section
     */
    private static function getProductSelectionSection(): Section
    {
        return Section::make(__('Choisir produit'))
            ->columnSpanFull()
            ->columns([
                'default' => 1,
                'md' => 2,
                'xl' => 4,
            ])
            ->schema([
                Select::make('product_id')
                    ->label(__('Produit'))
                    ->relationship('product', 'name')
                    ->native(false)
                    ->searchable()
                    ->preload()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get, ?string $state) => self::handleProductUpdate($set, $get, $state))
                    ->required(),
                Select::make('formula_id')
                    ->label(__('Formule'))
                    ->relationship('formula', 'name')
                    ->disabled()
                    ->dehydrated()
                    ->required(),
                Select::make('product_type_id')
                    ->label(__('Type de produit'))
                    ->relationship('productType', 'name')
                    ->disabled()
                    ->dehydrated()
                    ->helperText(__('Déterminé automatiquement par le produit sélectionné'))
                    ->nullable(),
                Select::make('batch_size_preset_id')
                    ->label(__('Préréglage de taille'))
                    ->options(fn (Get $get) => self::getBatchSizePresetOptions($get))
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get, ?string $state) => self::handleBatchSizePresetUpdate($set, $get, $state))
                    ->visible(fn (Get $get) => $get('product_type_id') !== null)
                    ->nullable(),
            ]);
    }

    /**
     * Build the batch size section.
     *
     * Contains fields for sizing mode, planned quantity, expected units, and waste.
     * The sizing mode determines whether planning is based on oil weight or final mass.
     *
     * @return Section The configured section
     */
    private static function getBatchSizeSection(): Section
    {
        return Section::make(__('Taille de batch'))
            ->columnSpanFull()
            ->columns([
                'default' => 1,
                'md' => 2,
                'xl' => 4,
            ])
            ->schema([
                Select::make('sizing_mode')
                    ->label(__('Mode de calcul'))
                    ->options(SizingMode::class)
                    ->required()
                    ->live(),
                TextInput::make('planned_quantity')
                    ->label(fn (Get $get) => self::getPlannedQuantityLabel($get))
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->suffix('kg'),
                TextInput::make('expected_units')
                    ->label(__('Unités attendues'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('expected_waste_kg')
                    ->label(__('Perte estimée (kg)'))
                    ->numeric()
                    ->minValue(0)
                    ->suffix('kg'),
                TextInput::make('actual_units')
                    ->label(__('Unités réelles'))
                    ->numeric()
                    ->minValue(0)
                    ->visibleOn('edit'),
            ]);
    }

    /**
     * Build the dates section.
     *
     * Contains production date and ready date pickers.
     * The ready date is auto-calculated based on product type (soaps: +35 days, others: +2 days).
     * When assigned to a wave, production date must be >= wave start date.
     *
     * @return Section The configured section
     */
    private static function getDatesSection(): Section
    {
        return Section::make(__('Dates'))
            ->columnSpanFull()
            ->schema([
                Fieldset::make(__('Période'))
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('production_date')
                            ->label(__('Date de production'))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, Get $get, ?string $state) => self::handleProductionDateUpdate($set, $get, $state))
                            ->minDate(fn (Get $get): ?string => self::getMinProductionDate($get))
                            ->helperText(fn (Get $get): ?string => self::getProductionDateHelperText($get))
                            ->default(now())
                            ->native(false)
                            ->weekStartsOnMonday(),
                        DatePicker::make('ready_date')
                            ->label(__('Date de disponibilité'))
                            ->afterOrEqual('production_date')
                            ->helperText(fn (Get $get): string => self::getReadyDateHelperText($get))
                            ->native(false)
                            ->weekStartsOnMonday(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ]),
            ]);
    }

    /**
     * Build the masterbatch configuration section for Planning tab.
     *
     * Contains:
     * - Toggle to create this production as a masterbatch
     * - Phase selection for masterbatch type
     * - Produced ingredient selection (for manufactured intermediates)
     *
     * Visible on create (editable) and edit (read-only for operator awareness).
     *
     * @return Section The configured section
     */
    private static function getMasterbatchConfigSection(): Section
    {
        return Section::make(__('Configuration Masterbatch'))
            ->columnSpanFull()
            ->columns([
                'default' => 1,
                'md' => 2,
            ])
            ->schema([
                Toggle::make('is_masterbatch')
                    ->label(__('Créer ce lot comme masterbatch'))
                    ->helperText(__('Activez pour fabriquer un masterbatch intermédiaire.'))
                    ->default(false)
                    ->live()
                    ->disabled(fn (string $operation) => $operation === 'edit')
                    ->afterStateUpdated(fn (Set $set, Get $get, ?bool $state) => self::handleMasterbatchToggle($set, $get, $state)),
                Select::make('replaces_phase')
                    ->label(__('Phase remplacée par ce masterbatch'))
                    ->options([
                        'saponified_oils' => 'Huiles Saponifiées',
                        'lye' => 'Milieux Réactionnel',
                        'additives' => 'Additifs',
                    ])
                    ->helperText(__('Définit quelle phase sera remplacée dans les futurs lots.'))
                    ->visible(fn (Get $get) => $get('is_masterbatch') === true)
                    ->required(fn (Get $get) => $get('is_masterbatch') === true)
                    ->disabled(fn (string $operation) => $operation === 'edit'),
                Select::make('produced_ingredient_id')
                    ->label(__('Ingrédient fabriqué (intermédiaire)'))
                    ->relationship(
                        name: 'producedIngredient',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_manufactured', true),
                    )
                    ->searchable()
                    ->preload()
                    ->helperText(__('Ingrédient de type fabriqué associé à ce masterbatch.'))
                    ->visible(fn (Get $get) => $get('is_masterbatch') === true)
                    ->required(fn (Get $get) => $get('is_masterbatch') === true)
                    ->dehydrated(fn (Get $get): bool => $get('is_masterbatch') === true)
                    ->disabled(fn (string $operation) => $operation === 'edit'),
            ])
            ->visible(fn (?Production $record): bool => $record?->is_masterbatch || $record === null);
    }

    /**
     * Build the Execution tab schema.
     *
     * Contains:
     * - Status management with controlled transitions
     * - Notes field
     * - Masterbatch configuration (create as masterbatch or use existing)
     *
     * @return Tab The configured tab
     */
    private static function getExecutionTab(): Tab
    {
        return Tab::make(__('Exécution'))
            ->icon(Heroicon::OutlinedPlay)
            ->hiddenOn('create')
            ->schema([
                self::getProductionFlowSection(),
                self::getMasterbatchSelectionSection(),
            ]);
    }

    /**
     * Build the production flow section.
     *
     * Contains status toggle buttons with controlled transitions and notes.
     * Status transitions are limited to valid next states to prevent inconsistencies.
     *
     * @return Section The configured section
     */
    private static function getProductionFlowSection(): Section
    {
        return Section::make(__('Flux de production'))
            ->columnSpanFull()
            ->columns([
                'default' => 1,
                'md' => 2,
                'xl' => 2,
            ])
            ->schema([
                Hidden::make('status')
                    ->default(ProductionStatus::Planned->value)
                    ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                ToggleButtons::make('status')
                    ->label(__('Statut'))
                    ->options(fn (?Production $record): array => self::getStatusOptions($record))
                    ->helperText(__('Transitions contrôlées pour éviter les incohérences de stock et de planification.'))
                    ->inline()
                    ->required()
                    ->visibleOn('edit'),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->columnSpanFull()
                    ->rows(3),
            ]);
    }

    /**
     * Build the masterbatch selection section for Execution tab.
     *
     * Contains selection for choosing an existing masterbatch to use
     * in this production. Only visible for non-masterbatch productions.
     *
     * @return Section The configured section
     */
    private static function getMasterbatchSelectionSection(): Section
    {
        return Section::make(__('Masterbatch à utiliser'))
            ->columnSpanFull()
            ->columns([
                'default' => 1,
                'md' => 2,
            ])
            ->schema([
                Select::make('masterbatch_lot_id')
                    ->label(__('Lot masterbatch à utiliser'))
                    ->options(fn () => self::getMasterbatchOptions())
                    ->searchable()
                    ->helperText(__('Choisissez un lot masterbatch déjà terminé, puis utilisez "Importer traçabilité MB" dans l\'onglet Composition.'))
                    ->placeholder(__('Aucun'))
                    ->nullable(),
            ])
            ->visibleOn('edit')
            ->visible(fn (?Production $record): bool => ! $record?->is_masterbatch);
    }

    /**
     * Build the Composition tab schema.
     *
     * Contains the production items editor Livewire component for managing
     * ingredient quantities, supply assignments, and phase tracking.
     *
     * Note: This tab is only visible on edit since items should only be
     * managed after the production is created.
     *
     * @return Tab The configured tab
     */
    private static function getCompositionTab(): Tab
    {
        return Tab::make(__('Composition & lots'))
            ->icon(Heroicon::OutlinedBeaker)
            ->hiddenOn('create')
            ->schema([
                Section::make(__('Gestion des items'))
                    ->description(__('Gérez les ingrédients, phases et lots supply de cette production.'))
                    ->schema([
                        TextEntry::make('items_info')
                            ->hiddenLabel()
                            ->state('Cliquez sur le bouton ci-dessous pour ouvrir l\'éditeur de composition.'),
                    ])
                    ->footer([
                        Action::make('manageItems')
                            ->label(__('Gérer les items'))
                            ->icon(Heroicon::OutlinedBeaker)
                            ->color('primary')
                            ->modalHeading(__('Composition & lots de production'))
                            ->modalDescription(__('Gérez les ingrédients, phases et lots supply de cette production.'))
                            ->modalWidth('7xl')
                            ->modalContent(fn (?Production $record) => view('components.production-items-modal', ['productionId' => $record?->id]))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel(__('Fermer'))
                            ->slideOver(),
                    ])
                    ->visibleOn('edit')
                    ->columnSpanFull(),
            ]);
    }

    // =========================================================================
    // Event Handlers
    // =========================================================================

    /**
     * Handle product selection update.
     *
     * Auto-populates formula, product type, and produced ingredient (for masterbatch).
     *
     * @param  Set  $set  The setter callback
     * @param  Get  $get  The getter callback
     * @param  string|null  $state  The selected product ID
     */
    private static function handleProductUpdate(Set $set, Get $get, ?string $state): void
    {
        if (! $state) {
            return;
        }

        $product = Product::find((int) $state);
        if (! $product) {
            return;
        }

        if ($get('is_masterbatch') === true && $product->produced_ingredient_id) {
            $set('produced_ingredient_id', $product->produced_ingredient_id);
        }

        $formula = $product->defaultFormula() ?? $product->formulas()->first();
        if ($formula) {
            $set('formula_id', $formula->id);
        }

        if ($product->product_type_id) {
            $set('product_type_id', $product->product_type_id);
            $productType = ProductType::find($product->product_type_id);
            if ($productType) {
                $set('sizing_mode', $productType->sizing_mode->value);
                $set('planned_quantity', $productType->default_batch_size);
                $set('expected_units', $productType->expected_units_output);
                $set('production_line_id', $productType->default_production_line_id);
            }
        }
    }

    /**
     * Handle product type selection update.
     *
     * Auto-populates sizing mode, batch size, expected units, and calculates ready date.
     *
     * @param  Set  $set  The setter callback
     * @param  Get  $get  The getter callback
     * @param  string|null  $state  The selected product type ID
     */
    private static function handleProductTypeUpdate(Set $set, Get $get, ?string $state): void
    {
        if (! $state) {
            return;
        }

        $productType = ProductType::find((int) $state);
        if (! $productType) {
            return;
        }

        $set('sizing_mode', $productType->sizing_mode->value);
        $set('planned_quantity', $productType->default_batch_size);
        $set('expected_units', $productType->expected_units_output);
        $set('production_line_id', $productType->default_production_line_id);

        $productionDate = $get('production_date');
        if ($productionDate) {
            $set(
                'ready_date',
                Production::estimateReadyDate(
                    $productionDate,
                    (string) ($productType->slug ?? ''),
                    (string) ($productType->name ?? ''),
                )->toDateString(),
            );
        }
    }

    /**
     * Handle batch size preset selection update.
     *
     * Auto-populates batch size, expected units, and waste from preset.
     *
     * @param  Set  $set  The setter callback
     * @param  Get  $get  The getter callback
     * @param  string|null  $state  The selected preset ID
     */
    private static function handleBatchSizePresetUpdate(Set $set, Get $get, ?string $state): void
    {
        if (! $state) {
            return;
        }

        $preset = BatchSizePreset::find((int) $state);
        if (! $preset) {
            return;
        }

        $set('planned_quantity', $preset->batch_size);
        $set('expected_units', $preset->expected_units);
        $set('expected_waste_kg', $preset->expected_waste_kg);
    }

    /**
     * Handle production date update.
     *
     * Auto-calculates ready date based on product type.
     *
     * @param  Set  $set  The setter callback
     * @param  Get  $get  The getter callback
     * @param  string|null  $state  The selected date
     */
    private static function handleProductionDateUpdate(Set $set, Get $get, ?string $state): void
    {
        if (! $state) {
            return;
        }

        $productType = ProductType::query()->find((int) ($get('product_type_id') ?? 0));

        $set(
            'ready_date',
            Production::estimateReadyDate(
                $state,
                (string) ($productType?->slug ?? ''),
                (string) ($productType?->name ?? ''),
            )->toDateString(),
        );
    }

    /**
     * Handle masterbatch toggle update.
     *
     * Clears or populates fields based on toggle state.
     *
     * @param  Set  $set  The setter callback
     * @param  Get  $get  The getter callback
     * @param  bool|null  $state  The toggle state
     */
    private static function handleMasterbatchToggle(Set $set, Get $get, ?bool $state): void
    {
        if ($state) {
            $set('masterbatch_lot_id', null);

            if (filled($get('produced_ingredient_id'))) {
                return;
            }

            $productId = $get('product_id');
            if (! $productId) {
                return;
            }

            $product = Product::query()->find((int) $productId);
            if ($product?->produced_ingredient_id) {
                $set('produced_ingredient_id', $product->produced_ingredient_id);
            }

            return;
        }

        $set('replaces_phase', null);
        $set('produced_ingredient_id', null);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Get the label for planned quantity based on sizing mode.
     *
     * @param  Get  $get  The getter callback
     * @return string The label
     */
    private static function getPlannedQuantityLabel(Get $get): string
    {
        return match ($get('sizing_mode')) {
            SizingMode::OilWeight->value => 'Poids d\'huiles (kg)',
            SizingMode::FinalMass->value => 'Masse finale (kg)',
            default => 'Quantité planifiée',
        };
    }

    /**
     * Get batch size preset options for the current product type.
     *
     * @param  Get  $get  The getter callback
     * @return array<int, string> The options
     */
    private static function getBatchSizePresetOptions(Get $get): array
    {
        $productTypeId = $get('product_type_id');
        if (! $productTypeId) {
            return [];
        }

        return BatchSizePreset::where('product_type_id', $productTypeId)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Get the minimum production date based on wave constraints.
     *
     * @param  Get  $get  The getter callback
     * @return string|null The minimum date or null
     */
    private static function getMinProductionDate(Get $get): ?string
    {
        $waveId = $get('production_wave_id');
        if (! $waveId) {
            return null;
        }

        $wave = ProductionWave::query()->find((int) $waveId);

        return $wave?->planned_start_date?->format('Y-m-d');
    }

    /**
     * Get the helper text for production date field.
     *
     * Shows wave constraint message when assigned to a wave.
     *
     * @param  Get  $get  The getter callback
     * @return string|null The helper text or null
     */
    private static function getProductionDateHelperText(Get $get): ?string
    {
        $waveId = $get('production_wave_id');
        if (! $waveId) {
            return null;
        }

        $wave = ProductionWave::query()->find((int) $waveId);
        if (! $wave?->planned_start_date) {
            return null;
        }

        return __('La date de production doit être >= au début de vague (:date).', ['date' => $wave->planned_start_date->format('d/m/Y')]);
    }

    private static function getReadyDateHelperText(Get $get): string
    {
        $productionDate = $get('production_date');

        if (! filled($productionDate)) {
            return __('Calcul automatique: savons +35 jours, autres types +2 jours (modifiable).');
        }

        $productType = null;
        $productTypeId = $get('product_type_id');

        if ($productTypeId) {
            $productType = ProductType::query()->find((int) $productTypeId);
        }

        $estimatedReadyDate = Production::estimateReadyDate(
            $productionDate,
            (string) ($productType?->slug ?? ''),
            (string) ($productType?->name ?? ''),
        );

        $delayDays = Carbon::parse((string) $productionDate)->diffInDays($estimatedReadyDate);

        return __('Calcul automatique estimé: :date (+:days jours, modifiable).', [
            'date' => $estimatedReadyDate->format('d/m/Y'),
            'days' => $delayDays,
        ]);
    }

    /**
     * Build line options for the production_line_id select.
     *
     * When the product type has allowed-line restrictions, only those lines are shown.
     * If the record already has a line that falls outside the allowed set (e.g. after a
     * retroactive config change), it is injected back into the list with an explanatory
     * suffix so the form stays usable rather than silently hiding the current value.
     *
     * When the product type has no restrictions, all active lines are shown (open mode).
     *
     * @return array<int, string>
     */
    private static function getProductionLineOptions(Get $get, ?Production $record): array
    {
        $productTypeId = (int) ($get('product_type_id') ?: $record?->product_type_id ?: 0);
        $currentLineId = (int) ($get('production_line_id') ?: $record?->production_line_id ?: 0);

        $productType = $productTypeId > 0
            ? ProductType::query()->with('allowedProductionLines')->find($productTypeId)
            : null;

        $allowedLineIds = $productType?->allowedProductionLines->modelKeys() ?? [];

        $query = ProductionLine::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($allowedLineIds !== []) {
            $query->whereIn('id', $allowedLineIds);
        }

        $options = $query->pluck('name', 'id')->all();

        if ($currentLineId > 0 && ! array_key_exists($currentLineId, $options)) {
            $currentLine = ProductionLine::query()->find($currentLineId);

            if ($currentLine) {
                $suffixes = [];

                if ($allowedLineIds !== [] && ! in_array($currentLineId, $allowedLineIds, true)) {
                    $suffixes[] = __('hors lignes autorisées');
                }

                if (! $currentLine->is_active) {
                    $suffixes[] = __('inactive');
                }

                $labelSuffix = $suffixes === []
                    ? __('non standard')
                    : implode(', ', $suffixes);

                $options[$currentLineId] = sprintf('%s (%s)', $currentLine->name, $labelSuffix);
            }
        }

        return $options;
    }

    /**
     * Get the available status options based on current status.
     *
     * Only shows valid transition states to prevent inconsistencies.
     *
     * @param  Production|null  $record  The current production record
     * @return array<string, string> The status options
     */
    private static function getStatusOptions(?Production $record): array
    {
        if (! $record?->status instanceof ProductionStatus) {
            return [
                ProductionStatus::Planned->value => ProductionStatus::Planned->getLabel(),
            ];
        }

        /** @var User|null $user */
        $user = auth()->user();

        return collect(Production::allowedTransitionsFor($record->status))
            ->filter(fn (ProductionStatus $status): bool => $user?->canSetProductionStatus($record->status, $status) ?? false)
            ->mapWithKeys(fn (ProductionStatus $status): array => [$status->value => $status->getLabel()])
            ->all();
    }

    /**
     * Get the available masterbatch options.
     *
     * Returns finished masterbatches with their phase information.
     *
     * @return array<int, string> The masterbatch options
     */
    private static function getMasterbatchOptions(): array
    {
        return Production::query()
            ->where('is_masterbatch', true)
            ->whereNotNull('replaces_phase')
            ->where('status', 'finished')
            ->with('product:id,name')
            ->orderByDesc('production_date')
            ->get()
            ->mapWithKeys(function (Production $masterbatch): array {
                $phaseLabel = Phases::labelFor($masterbatch->replaces_phase, __('Phase inconnue'));

                return [
                    $masterbatch->id => trim($masterbatch->getLotDisplayLabel().' - '.($masterbatch->product?->name ?? 'Masterbatch').' ('.$phaseLabel.')'),
                ];
            })
            ->toArray();
    }
}
