<?php

namespace App\Filament\Resources\Production;

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
use App\Models\Production\Product;
use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use Filament\Actions\Action;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class ProductionResource extends Resource
{
    protected static ?string $model = Production::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Production';

    protected static ?string $navigationLabel = 'Productions';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Lot de production')
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
                            ->label('Numéro de batch')
                            ->required()
                            ->maxLength(255)
                            ->default('B'.now()->format('YmdHis'))
                            ->unique(ignoreRecord: true),
                    ])
                    ->columns(2),

                Section::make('Choisir produit')
                    ->schema([
                        Select::make('product_id')
                            ->label('Produit')
                            ->relationship('product', 'name')
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
                                if (! $state) {
                                    return;
                                }
                                $product = Product::find((int) $state);
                                if ($product) {
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
                    ])
                    ->columns(3),

                Section::make('Taille de batch')
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
                    ])
                    ->columns(3),

                Section::make('Masterbatch')
                    ->schema([
                        Toggle::make('is_masterbatch')
                            ->label('Créer ce lot comme masterbatch')
                            ->helperText('Activé: vous fabriquez un masterbatch intermédiaire. Désactivé: vous utilisez un masterbatch existant.')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?bool $state): void {
                                if ($state) {
                                    $set('masterbatch_lot_id', null);

                                    return;
                                }

                                $set('replaces_phase', null);
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
                                            $masterbatch->id => $masterbatch->batch_number.' - '.$phaseLabel,
                                        ];
                                    })
                                    ->toArray();
                            })
                            ->searchable()
                            ->helperText('Choisissez un lot masterbatch déjà terminé, puis utilisez "Importer traçabilité MB" pour copier les lots supply.')
                            ->placeholder('Aucun')
                            ->visible(fn (Get $get) => $get('is_masterbatch') !== true)
                            ->nullable(),
                    ])
                    ->visible(fn (string $operation) => $operation !== 'view')
                    ->collapsible(),

                Section::make('Détails')
                    ->schema([
                        TextInput::make('slug')
                            ->maxLength(255)
                            ->visibleOn('edit'),
                        ToggleButtons::make('status')
                            ->label('Statut')
                            ->options(ProductionStatus::class)
                            ->inline()
                            ->required()
                            ->default(ProductionStatus::Planned),
                        Toggle::make('organic')
                            ->label('Bio')
                            ->default(true),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->columnSpanFull()
                            ->rows(3),
                    ])
                    ->columns(3),

                Section::make('Dates')
                    ->schema([
                        Fieldset::make('Période')
                            ->schema([
                                DatePicker::make('production_date')
                                    ->label('Date de production')
                                    ->required()
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
                                    ->native(false)
                                    ->weekStartsOnMonday(),
                            ])
                            ->columns(2),
                    ]),

                Section::make('Items de production')
                    ->schema([
                        Repeater::make('productionItems')
                            ->relationship()
                            ->schema([
                                Select::make('ingredient_id')
                                    ->label('Ingrédient')
                                    ->options(Ingredient::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, $state, $old): void {
                                        if ((string) ($old ?? '') === '' || (string) $old === (string) ($state ?? '')) {
                                            return;
                                        }

                                        $set('supply_id', null);
                                        $set('supply_batch_number', null);
                                        $set('supplier_listing_id', null);
                                    }),
                                Hidden::make('supplier_listing_id'),
                                Select::make('supply_id')
                                    ->label('Supply sélectionné')
                                    ->options(function (Get $get): array {
                                        $ingredientId = $get('ingredient_id');

                                        if (! $ingredientId) {
                                            return [];
                                        }

                                        return Supply::query()
                                            ->where('is_in_stock', true)
                                            ->whereHas('supplierListing', function (Builder $builder) use ($ingredientId): void {
                                                $builder->where('ingredient_id', $ingredientId);
                                            })
                                            ->orderBy('expiry_date')
                                            ->get()
                                            ->mapWithKeys(fn (Supply $supply): array => [
                                                $supply->id => sprintf('%s (%.3f kg)', $supply->batch_number, $supply->getAvailableQuantity()),
                                            ])
                                            ->toArray();
                                    })
                                    ->searchable()
                                    ->nullable()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        if (! $state) {
                                            $set('supply_batch_number', null);

                                            return;
                                        }

                                        $supply = Supply::query()->find((int) $state);

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
                                    ->dehydrated(),
                                TextEntry::make('calculated_quantity')
                                    ->label('Quantité calculée (kg)')
                                    ->state(function (Get $get): string {
                                        $plannedQuantity = (float) ($get('../../planned_quantity') ?? 0);
                                        $percentage = (float) ($get('percentage_of_oils') ?? 0);
                                        $calculatedQuantity = round(($plannedQuantity * $percentage) / 100, 3);

                                        return number_format($calculatedQuantity, 3, '.', ' ').' kg';
                                    }),
                                Select::make('phase')
                                    ->label('Phase')
                                    ->options(Phases::class)
                                    ->required(),
                                TextInput::make('percentage_of_oils')
                                    ->label('% d\'huiles')
                                    ->numeric()
                                    ->live()
                                    ->required(),
                                Toggle::make('organic')
                                    ->label('Bio')
                                    ->default(true),
                                Toggle::make('is_supplied')
                                    ->label('Approvisionné')
                                    ->default(false),
                                TextInput::make('sort')
                                    ->numeric()
                                    ->default(0)
                                    ->hidden(),
                            ])
                            ->columns(3)
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
                TextColumn::make('batch_number')
                    ->label('Batch')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Produit')
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
                    ->trueIcon('heroicon-o-beaker')
                    ->falseIcon('heroicon-o-minus'),
                IconColumn::make('uses_masterbatch')
                    ->label('Utilise MB')
                    ->boolean()
                    ->getStateUsing(fn (Production $record): bool => $record->masterbatch_lot_id !== null)
                    ->trueIcon('heroicon-o-link'),
                TextColumn::make('production_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
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
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (Production $record): void {
                        $duplicate = $record->replicate();
                        $duplicate->status = ProductionStatus::Planned;
                        $duplicate->actual_units = null;
                        $duplicate->batch_number = self::generateDuplicatedBatchNumber($record->batch_number);
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
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
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

    private static function generateDuplicatedBatchNumber(string $originalBatchNumber): string
    {
        $baseBatchNumber = self::getDuplicateBaseBatchNumber($originalBatchNumber);

        $existingDuplicates = Production::query()
            ->where('batch_number', 'like', $baseBatchNumber.'-D%')
            ->pluck('batch_number');

        $usedIndexes = $existingDuplicates
            ->map(function (string $batchNumber) use ($baseBatchNumber): ?int {
                if (! preg_match('/^'.preg_quote($baseBatchNumber, '/').'-D(\d{2})$/', $batchNumber, $matches)) {
                    return null;
                }

                return (int) $matches[1];
            })
            ->filter()
            ->values()
            ->all();

        for ($attempt = 1; $attempt <= 99; $attempt++) {
            if (! in_array($attempt, $usedIndexes, true)) {
                return $baseBatchNumber.'-D'.str_pad((string) $attempt, 2, '0', STR_PAD_LEFT);
            }
        }

        return $baseBatchNumber.'-D'.strtoupper(Str::random(4));
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

    private static function getDuplicateBaseBatchNumber(string $batchNumber): string
    {
        return preg_replace('/(?:-D[A-Z0-9]{2,4})+$/', '', $batchNumber) ?: $batchNumber;
    }
}
