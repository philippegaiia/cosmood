<?php

namespace App\Filament\Resources\Production;

use App\Enums\FormulaItemCalculationMode;
use App\Enums\IngredientBaseUnit;
use App\Enums\Phases;
use App\Enums\ProductionStatus;
use App\Enums\SizingMode;
use App\Filament\Resources\Production\ProductionResource\Pages\CreateProduction;
use App\Filament\Resources\Production\ProductionResource\Pages\EditProduction;
use App\Filament\Resources\Production\ProductionResource\Pages\ListProductions;
use App\Filament\Resources\Production\ProductionResource\Pages\ViewProduction;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionItemsRelationManager;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionQcChecksRelationManager;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionTasksRelationManager;
use App\Models\Production\BatchSizePreset;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use App\Services\Production\PermanentBatchNumberService;
use App\Services\Production\PlanningBatchNumberService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class ProductionResource extends Resource
{
    protected static ?string $model = Production::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Production';

    protected static ?string $navigationLabel = 'Productions';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('production_flow')
                    ->columnSpanFull()
                    ->tabs([
                        self::getPlanningTabSchema(),
                        self::getExecutionTabSchema(),
                        self::getCompositionTabSchema(),
                    ]),
            ]);
    }

    private static function getPlanningTabSchema(): Tab
    {
        return Tab::make('Planification')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->schema([
                Section::make('Lot de production')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'md' => 3,
                    ])
                    ->schema([
                        Select::make('production_wave_id')
                            ->label('Vague de production')
                            ->relationship('wave', 'name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->placeholder('Aucune (production autonome)')
                            ->nullable(),
                        TextInput::make('batch_number')
                            ->label('Réf. planification')
                            ->helperText('Si vide, une référence courte est attribuée automatiquement (ex: T00001).')
                            ->placeholder('Auto (T00001)')
                            ->required(fn (string $operation): bool => $operation === 'edit')
                            ->maxLength(255)
                            ->unique(),
                        TextInput::make('permanent_batch_number')
                            ->label('Lot permanent')
                            ->placeholder('Attribué automatiquement au démarrage')
                            ->disabled()
                            ->dehydrated(false),
                    ]),

                Section::make('Choisir produit')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
                    ->schema([
                        Select::make('product_id')
                            ->label('Produit')
                            ->relationship('product', 'name')
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
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

                                $formula = $product->formulas()->first();
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
                                    }
                                }
                            })
                            ->required(),
                        Select::make('formula_id')
                            ->label('Formule')
                            ->relationship('formula', 'name')
                            ->disabled()
                            ->dehydrated()
                            ->required(),
                        Select::make('product_type_id')
                            ->label('Type de produit')
                            ->relationship('productType', 'name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
                                if (! $state) {
                                    return;
                                }
                                $productType = ProductType::find((int) $state);
                                if ($productType) {
                                    $set('sizing_mode', $productType->sizing_mode->value);
                                    $set('planned_quantity', $productType->default_batch_size);
                                    $set('expected_units', $productType->expected_units_output);

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
                            })
                            ->nullable(),
                        Select::make('batch_size_preset_id')
                            ->label('Préréglage de taille')
                            ->options(function (Get $get) {
                                $productTypeId = $get('product_type_id');
                                if (! $productTypeId) {
                                    return [];
                                }

                                return BatchSizePreset::where('product_type_id', $productTypeId)
                                    ->pluck('name', 'id');
                            })
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
                                if (! $state) {
                                    return;
                                }
                                $preset = BatchSizePreset::find((int) $state);
                                if ($preset) {
                                    $set('planned_quantity', $preset->batch_size);
                                    $set('expected_units', $preset->expected_units);
                                    $set('expected_waste_kg', $preset->expected_waste_kg);
                                }
                            })
                            ->visible(fn (Get $get) => $get('product_type_id') !== null)
                            ->nullable(),
                    ]),

                Section::make('Taille de batch')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
                    ->schema([
                        Select::make('sizing_mode')
                            ->label('Mode de calcul')
                            ->options(SizingMode::class)
                            ->required()
                            ->live(),
                        TextInput::make('planned_quantity')
                            ->label(function (Get $get) {
                                return match ($get('sizing_mode')) {
                                    SizingMode::OilWeight->value => 'Poids d\'huiles (kg)',
                                    SizingMode::FinalMass->value => 'Masse finale (kg)',
                                    default => 'Quantité planifiée',
                                };
                            })
                            ->numeric()
                            ->required()
                            ->suffix('kg'),
                        TextInput::make('expected_units')
                            ->label('Unités attendues')
                            ->numeric()
                            ->required(),
                        TextInput::make('expected_waste_kg')
                            ->label('Perte estimée (kg)')
                            ->numeric()
                            ->suffix('kg'),
                        TextInput::make('actual_units')
                            ->label('Unités réelles')
                            ->numeric()
                            ->visibleOn('edit'),
                    ]),

                Section::make('Dates')
                    ->columnSpanFull()
                    ->schema([
                        Fieldset::make('Période')
                            ->columnSpanFull()
                            ->schema([
                                DatePicker::make('production_date')
                                    ->label('Date de production')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
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
                                    })
                                    ->minDate(function (Get $get): ?string {
                                        $waveId = $get('production_wave_id');

                                        if (! $waveId) {
                                            return null;
                                        }

                                        $wave = ProductionWave::query()->find((int) $waveId);

                                        return $wave?->planned_start_date?->format('Y-m-d');
                                    })
                                    ->helperText(function (Get $get): ?string {
                                        $waveId = $get('production_wave_id');

                                        if (! $waveId) {
                                            return null;
                                        }

                                        $wave = ProductionWave::query()->find((int) $waveId);

                                        if (! $wave?->planned_start_date) {
                                            return null;
                                        }

                                        return 'La date de production doit être >= au début de vague ('.$wave->planned_start_date->format('d/m/Y').').';
                                    })
                                    ->default(now())
                                    ->native(false)
                                    ->weekStartsOnMonday(),
                                DatePicker::make('ready_date')
                                    ->label('Date de disponibilité')
                                    ->afterOrEqual('production_date')
                                    ->helperText('Calcul automatique: savons +35 jours, autres types +2 jours (modifiable).')
                                    ->native(false)
                                    ->weekStartsOnMonday(),
                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                            ]),
                    ]),
            ]);
    }

    private static function getExecutionTabSchema(): Tab
    {
        return Tab::make('Exécution')
            ->icon(Heroicon::OutlinedPlay)
            ->schema([
                Section::make('Flux de production')
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
                            ->label('Statut')
                            ->options(function (?Production $record): array {
                                if (! $record?->status instanceof ProductionStatus) {
                                    return [
                                        ProductionStatus::Planned->value => ProductionStatus::Planned->getLabel(),
                                    ];
                                }

                                return collect(Production::allowedTransitionsFor($record->status))
                                    ->mapWithKeys(fn (ProductionStatus $status): array => [$status->value => $status->getLabel()])
                                    ->all();
                            })
                            ->helperText('Transitions contrôlées pour éviter les incohérences de stock et de planification.')
                            ->inline()
                            ->required()
                            ->visibleOn('edit'),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->columnSpanFull()
                            ->rows(3),
                    ]),

                Section::make('Masterbatch')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->schema([
                        Toggle::make('is_masterbatch')
                            ->label('Créer ce lot comme masterbatch')
                            ->helperText('Activé: vous fabriquez un masterbatch intermédiaire. Désactivé: vous utilisez un masterbatch existant.')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, ?bool $state): void {
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
                            })
                            ->disabled(fn (string $operation) => $operation === 'edit'),
                        Select::make('replaces_phase')
                            ->label('Phase remplacée par ce masterbatch')
                            ->options([
                                'saponified_oils' => 'Huiles Saponifiées',
                                'lye' => 'Milieux Réactionnel',
                                'additives' => 'Additifs',
                            ])
                            ->helperText('Définit quelle phase sera remplacée dans les futurs lots utilisant ce masterbatch.')
                            ->visible(fn (Get $get) => $get('is_masterbatch') === true)
                            ->required(fn (Get $get) => $get('is_masterbatch') === true),
                        Select::make('masterbatch_lot_id')
                            ->label('Lot masterbatch à utiliser')
                            ->options(function () {
                                return Production::query()
                                    ->where('is_masterbatch', true)
                                    ->whereNotNull('replaces_phase')
                                    ->where('status', 'finished')
                                    ->with('product:id,name')
                                    ->orderByDesc('production_date')
                                    ->get()
                                    ->mapWithKeys(function (Production $masterbatch): array {
                                        $phaseLabel = match ($masterbatch->replaces_phase) {
                                            'saponified_oils' => 'Huiles Saponifiées',
                                            'lye' => 'Milieux Réactionnel',
                                            'additives' => 'Additifs',
                                            default => Phases::tryFrom((string) $masterbatch->replaces_phase)?->getLabel() ?? 'Phase inconnue',
                                        };

                                        return [
                                            $masterbatch->id => trim($masterbatch->getLotDisplayLabel().' - '.($masterbatch->product?->name ?? 'Masterbatch').' ('.$phaseLabel.')'),
                                        ];
                                    })
                                    ->toArray();
                            })
                            ->searchable()
                            ->helperText('Choisissez un lot masterbatch déjà terminé, puis utilisez "Importer traçabilité MB" pour copier les lots supply.')
                            ->placeholder('Aucun')
                            ->visible(fn (Get $get) => $get('is_masterbatch') !== true)
                            ->nullable(),
                        Select::make('produced_ingredient_id')
                            ->label('Ingrédient fabriqué (intermédiaire)')
                            ->relationship(
                                name: 'producedIngredient',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_manufactured', true),
                            )
                            ->searchable()
                            ->preload()
                            ->helperText('Choisir un ingrédient de type fabriqué (ex: masterbatch, macérat).')
                            ->visible(fn (Get $get) => $get('is_masterbatch') === true)
                            ->dehydrated(fn (Get $get): bool => $get('is_masterbatch') === true)
                            ->nullable(),
                    ])
                    ->visible(fn (string $operation) => $operation !== 'view')
                    ->collapsible(),
            ]);
    }

    private static function getCompositionTabSchema(): Tab
    {
        return Tab::make('Composition & lots')
            ->icon(Heroicon::OutlinedBeaker)
            ->schema([
                Section::make('Items de production')
                    ->schema([
                        TextEntry::make('saponified_total_control')
                            ->label('Controle saponifie')
                            ->columnSpanFull()
                            ->state(function (Get $get): string {
                                $formulaId = (int) ($get('formula_id') ?? 0);
                                $items = $get('productionItems') ?? [];

                                if (! self::shouldApplySaponifiedControlFromProductionState($formulaId)) {
                                    return 'N/A (controle desactive sur la formule)';
                                }

                                $total = self::calculateSaponifiedTotalFromProductionItems($items);

                                return number_format($total, 2, '.', ' ').' % (cible 100 %)';
                            })
                            ->color(function (Get $get): string {
                                $formulaId = (int) ($get('formula_id') ?? 0);
                                $items = $get('productionItems') ?? [];

                                if (! self::shouldApplySaponifiedControlFromProductionState($formulaId)) {
                                    return 'gray';
                                }

                                $total = self::calculateSaponifiedTotalFromProductionItems($items);

                                return abs($total - 100.0) < 0.01 ? 'success' : 'danger';
                            }),
                        Repeater::make('productionItems')
                            ->relationship()
                            ->schema([
                                Select::make('ingredient_id')
                                    ->label('Ingrédient')
                                    ->options(Ingredient::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->columnSpan(2)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, $state, $old): void {
                                        if ((string) ($old ?? '') === '' || (string) $old === (string) ($state ?? '')) {
                                            return;
                                        }

                                        $set('supply_id', null);
                                        $set('supply_batch_number', null);
                                        $set('supplier_listing_id', null);

                                        $ingredient = Ingredient::query()->find((int) ($state ?? 0));

                                        if (! $ingredient) {
                                            return;
                                        }

                                        $set(
                                            'calculation_mode',
                                            ($ingredient->base_unit?->value ?? IngredientBaseUnit::Kg->value) === IngredientBaseUnit::Unit->value
                                                ? FormulaItemCalculationMode::QuantityPerUnit->value
                                                : FormulaItemCalculationMode::PercentOfOils->value,
                                        );
                                    }),
                                Hidden::make('calculation_mode')
                                    ->default(FormulaItemCalculationMode::PercentOfOils->value),
                                Hidden::make('supplier_listing_id'),
                                Select::make('supply_id')
                                    ->label('Supply sélectionné')
                                    ->options(function (Get $get): array {
                                        $ingredientId = $get('ingredient_id');

                                        if (! $ingredientId) {
                                            return [];
                                        }

                                        return Supply::query()
                                            ->with('supplierListing:id,unit_of_measure,ingredient_id')
                                            ->where('is_in_stock', true)
                                            ->whereHas('supplierListing', function (Builder $builder) use ($ingredientId): void {
                                                $builder->where('ingredient_id', $ingredientId);
                                            })
                                            ->orderBy('expiry_date')
                                            ->get()
                                            ->mapWithKeys(fn (Supply $supply): array => [
                                                $supply->id => sprintf(
                                                    '%s (%.3f %s)',
                                                    $supply->batch_number,
                                                    $supply->getAvailableQuantity(),
                                                    $supply->getUnitOfMeasure(),
                                                ),
                                            ])
                                            ->toArray();
                                    })
                                    ->searchable()
                                    ->nullable()
                                    ->columnSpan(2)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        if (! $state) {
                                            $set('supply_batch_number', null);

                                            return;
                                        }

                                        $supply = Supply::query()
                                            ->with('supplierListing:id,ingredient_id')
                                            ->find((int) $state);

                                        if (! $supply) {
                                            return;
                                        }

                                        $set('supply_batch_number', $supply->batch_number);
                                        $set('supplier_listing_id', $supply->supplier_listing_id);
                                        $set('ingredient_id', $supply->supplierListing?->ingredient_id);
                                        $set('is_supplied', true);
                                    }),
                                TextInput::make('supply_batch_number')
                                    ->label('Lot supply')
                                    ->disabled()
                                    ->columnSpan(1)
                                    ->dehydrated(),
                                TextEntry::make('calculated_quantity')
                                    ->label('Quantité calculée')
                                    ->columnSpan(1)
                                    ->state(function (Get $get): string {
                                        $plannedQuantity = (float) ($get('../../planned_quantity') ?? 0);
                                        $expectedUnits = (float) ($get('../../expected_units') ?? 0);
                                        $percentage = (float) ($get('percentage_of_oils') ?? 0);
                                        $phaseState = $get('phase');
                                        $phase = $phaseState instanceof Phases ? $phaseState->value : (string) ($phaseState ?? '');
                                        $modeState = $get('calculation_mode');
                                        $mode = $modeState instanceof FormulaItemCalculationMode
                                            ? $modeState
                                            : FormulaItemCalculationMode::tryFrom((string) ($modeState ?? ''))
                                            ?? ($phase === Phases::Packaging->value
                                                ? FormulaItemCalculationMode::QuantityPerUnit
                                                : FormulaItemCalculationMode::PercentOfOils);

                                        $calculatedQuantity = $mode === FormulaItemCalculationMode::QuantityPerUnit
                                            ? round($expectedUnits * $percentage, 3)
                                            : round(($plannedQuantity * $percentage) / 100, 3);

                                        $suffix = $mode === FormulaItemCalculationMode::QuantityPerUnit ? ' u' : ' kg';

                                        return number_format($calculatedQuantity, 3, '.', ' ').$suffix;
                                    }),
                                Select::make('phase')
                                    ->label('Phase')
                                    ->options(Phases::class)
                                    ->columnSpan(1)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, $state): void {
                                        $phase = $state instanceof Phases ? $state->value : (string) ($state ?? '');

                                        if ($phase === Phases::Packaging->value) {
                                            $set('calculation_mode', FormulaItemCalculationMode::QuantityPerUnit->value);
                                        }
                                    })
                                    ->required(),
                                TextInput::make('percentage_of_oils')
                                    ->label(function (Get $get): string {
                                        $phaseState = $get('phase');
                                        $phase = $phaseState instanceof Phases ? $phaseState->value : (string) ($phaseState ?? '');
                                        $modeState = $get('calculation_mode');
                                        $mode = $modeState instanceof FormulaItemCalculationMode
                                            ? $modeState
                                            : FormulaItemCalculationMode::tryFrom((string) ($modeState ?? ''))
                                            ?? ($phase === Phases::Packaging->value
                                                ? FormulaItemCalculationMode::QuantityPerUnit
                                                : FormulaItemCalculationMode::PercentOfOils);

                                        return $mode === FormulaItemCalculationMode::QuantityPerUnit ? 'Qté / unité' : '% d\'huiles';
                                    })
                                    ->helperText('Mode unitaire: saisir la quantité par unité. Sinon, saisir le % d\'huiles.')
                                    ->numeric()
                                    ->columnSpan(1)
                                    ->live()
                                    ->default(1)
                                    ->dehydrateStateUsing(fn (mixed $state): float => (float) ($state ?? 1))
                                    ->required(),
                                Toggle::make('organic')
                                    ->label('Bio')
                                    ->columnSpan(1)
                                    ->default(true),
                                Toggle::make('is_supplied')
                                    ->label('Approvisionné')
                                    ->columnSpan(1)
                                    ->default(false),
                                TextInput::make('sort')
                                    ->numeric()
                                    ->default(0)
                                    ->hidden(),
                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                                'xl' => 6,
                            ])
                            ->addable(fn (Get $get): bool => (string) ($get('status') ?? '') !== ProductionStatus::Finished->value)
                            ->deletable(fn (Get $get): bool => (string) ($get('status') ?? '') !== ProductionStatus::Finished->value)
                            ->reorderableWithButtons()
                            ->orderColumn('sort'),
                    ])
                    ->visibleOn('edit')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
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
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('supply_coverage')
                    ->label('Appro')
                    ->state(fn (Production $record): string => $record->getSupplyCoverageLabel())
                    ->badge()
                    ->color(fn (Production $record): string => $record->getSupplyCoverageColor()),
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
                TrashedFilter::make(),
                SelectFilter::make('production_wave_id')
                    ->label('Vague')
                    ->relationship('wave', 'name'),
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(ProductionStatus::class),
            ])
            ->recordActions([
                Action::make('duplicate')
                    ->label('Dupliquer')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (Production $record): void {
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
                    }),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('assignPermanentBatchNumbers')
                    ->label('Attribuer lots permanents')
                    ->icon(Heroicon::OutlinedHashtag)
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $assigned = app(PermanentBatchNumberService::class)
                            ->assignForProductions($records->pluck('id')->all());

                        Notification::make()
                            ->title('Lots permanents attribués')
                            ->body($assigned.' lot(s) permanent(s) attribué(s).')
                            ->success()
                            ->send();
                    }),
                BulkAction::make('printSelectedDocuments')
                    ->label('Imprimer fiches sélectionnées')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(function (Collection $selectedRecords): string {
                        $ids = $selectedRecords
                            ->pluck('id')
                            ->map(fn ($id): int => (int) $id)
                            ->implode(',');

                        return route('productions.bulk-documents', [
                            'ids' => $ids,
                        ]);
                    })
                    ->openUrlInNewTab(),
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords(fn (Production $record): bool => $record->status !== ProductionStatus::Finished),
                    ForceDeleteBulkAction::make()
                        ->authorizeIndividualRecords(fn (Production $record): bool => $record->status !== ProductionStatus::Finished),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('ingredientRequirements'))
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            ProductionItemsRelationManager::class,
            ProductionTasksRelationManager::class,
            ProductionQcChecksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductions::route('/'),
            'create' => CreateProduction::route('/create'),
            'view' => ViewProduction::route('/{record}'),
            'edit' => EditProduction::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

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
     * @param  array<int, array<string, mixed>>  $items
     */
    private static function calculateSaponifiedTotalFromProductionItems(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            if (($item['phase'] ?? null) !== Phases::Saponification->value) {
                continue;
            }

            $total += (float) ($item['percentage_of_oils'] ?? 0);
        }

        return $total;
    }

    private static function shouldApplySaponifiedControlFromProductionState(int $formulaId): bool
    {
        return self::isSoapFormula($formulaId);
    }

    private static function isSoapFormula(int $formulaId): bool
    {
        if ($formulaId <= 0) {
            return false;
        }

        static $cache = [];

        if (array_key_exists($formulaId, $cache)) {
            return $cache[$formulaId];
        }

        return $cache[$formulaId] = (bool) (Formula::query()->find($formulaId)?->is_soap ?? false);
    }
}
